import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { test } from 'node:test';
import { analyzeArtwork, buildParts } from '../geometry.js';
import { baseFits, keycapConfig, stemClearance } from '../keycap.js';
import { isClosedManifold } from '../mesh.js';
import { pointInPolygons } from '../solids.js';
import { FILLED_NOTE, HULL_NOTE, traceOutline } from '../trace-outline.js';
import { KEYCAP_BODY, SETTINGS, logoArtwork, makeImage, monogramArtwork, ownerLikeLogo } from './helpers.js';

const FIT_BLOCKER = 'This shape is too small to hold the switch base. Make the artwork larger or choose Square or Circle.';
const STEM_BLOCKER = 'This shape is too thin to hold the switch stem. Make the artwork larger or choose Square or Circle.';
const keycap = keycapConfig({});
const whole = (ring) => [{ outer: ring, holes: [] }];
const BLACK = [{ hex: '#111111', height_mm: 1, enabled: true }];

function distanceToRing([px, py], ring) {
    let best = Infinity;
    ring.forEach(([ax, ay], i) => {
        const [bx, by] = ring[(i + 1) % ring.length];
        const [dx, dy] = [bx - ax, by - ay];
        const t = Math.max(0, Math.min(1, ((px - ax) * dx + (py - ay) * dy) / (dx * dx + dy * dy || 1)));
        best = Math.min(best, Math.hypot(px - ax - t * dx, py - ay - t * dy));
    });

    return best;
}

/** The frame analyzeArtwork uses for Trace: the image fitted into a 30 mm square centred on the origin. */
function frameFor(image, size = 30) {
    const mmPerPx = Math.min(size / image.width, size / image.height);

    return { left: (-image.width * mmPerPx) / 2, top: (image.height * mmPerPx) / 2, mmPerPx };
}

test('the filled-in note is its own sentence, distinct from the separate-pieces note', () => {
    assert.equal(FILLED_NOTE, 'The outline was filled in around your artwork so the cap can hold the switch.');
    assert.notEqual(FILLED_NOTE, HULL_NOTE);
});

for (const scale of [1, 0.8]) {
    for (const hollow of [false, true]) {
        test(`a sparse monogram at scale ${scale} (hollow ${hollow}) is solidified to hold the switch instead of blocked`, () => {
            const settings = { ...SETTINGS, colors: BLACK, base_shape: 'trace', scale, hollow_cap: hollow };
            for (const quality of ['preview', 'final']) {
                const analysis = analyzeArtwork(monogramArtwork(), settings, KEYCAP_BODY, quality);
                const { outline, stem, baseOutline } = analysis.cap;

                assert.deepEqual(analysis.blockers, [], quality);
                assert.ok(analysis.notes.includes(FILLED_NOTE), analysis.notes.join(' | '));
                assert.ok(!analysis.notes.includes(HULL_NOTE));
                assert.ok(baseFits({ cap: whole(outline), base: whole(baseOutline), stem, keycap }), 'the cap outline holds the stem square');

                const parts = buildParts(analysis, settings, KEYCAP_BODY);
                const [cap, base] = parts.slice(-2);
                assert.equal(cap.name, 'Cap');
                assert.ok(isClosedManifold(cap));
                assert.equal(base.name, 'Base');
                assert.ok(isClosedManifold(base));
                if (hollow) assert.ok(analysis.cap.hollowBody, 'the hollow cap was built');

                // Every colour vertex is inside the outline, with the rim (less a cell) to spare.
                for (const part of parts.filter((p) => p.kind === 'colour')) {
                    for (let i = 0; i < part.positions.length; i += 3) {
                        const point = [part.positions[i], part.positions[i + 1]];
                        assert.ok(pointInPolygons(point, whole(outline)));
                        assert.ok(
                            distanceToRing(point, outline) >= keycap.rim - 0.2,
                            `colour vertex ${distanceToRing(point, outline)} mm from the outline`,
                        );
                    }
                }
                if (quality === 'final') buildParts(analysis, settings, KEYCAP_BODY, { layout: 'print' });
            }
        });
    }
}

test('ordinary logos keep exactly the outline they had before, with no filled-in note', () => {
    // Fingerprints of the outlines before solidifying existed (sha256 of the JSON of the outline ring, 16 hex).
    const hash = (outline) => createHash('sha256').update(JSON.stringify(outline)).digest('hex').slice(0, 16);
    for (const [image, expected] of [
        [ownerLikeLogo(500), '3345c82caa6e2aed'],
        [logoArtwork(500), 'bc5830847b4aec2a'],
        [logoArtwork(300), '544c780e82aeddac'],
    ]) {
        for (const quality of ['preview', 'final']) {
            const analysis = analyzeArtwork(image, { ...SETTINGS, base_shape: 'trace' }, KEYCAP_BODY, quality);
            assert.equal(hash(analysis.cap.outline), expected);
            assert.deepEqual(analysis.blockers, []);
            assert.ok(!analysis.notes.includes(FILLED_NOTE), analysis.notes.join(' | '));
        }
    }
});

test('artwork too small even as a convex hull is still blocked with the existing messages', () => {
    const blob = makeImage(300, 300, (x, y) => (Math.hypot(x + 0.5 - 150, y + 0.5 - 150) < 30 ? [29, 78, 216] : null));
    const blobbed = analyzeArtwork(blob, { ...SETTINGS, base_shape: 'trace' }, KEYCAP_BODY, 'preview');
    assert.deepEqual(blobbed.blockers, [FIT_BLOCKER]);
    assert.ok(!blobbed.notes.includes(FILLED_NOTE));

    const line = makeImage(220, 220, (x, y) => (x >= 10 && x < 210 && y >= 108 && y < 112 ? [29, 78, 216] : null));
    const thin = analyzeArtwork(line, { ...SETTINGS, base_shape: 'trace' }, KEYCAP_BODY, 'preview');
    assert.deepEqual(thin.blockers, [STEM_BLOCKER]);
    assert.ok(!thin.notes.includes(FILLED_NOTE));
});

test('a plus-shaped silhouette puts the stem at the crossing', () => {
    // Bars 60 px wide across a 300 px image (6 mm at the 30 mm fit).
    const plus = makeImage(300, 300, (x, y) => ((x >= 120 && x < 180) || (y >= 120 && y < 180) ? [29, 78, 216] : null));
    const traced = traceOutline({ image: plus, frame: frameFor(plus), keycap });

    assert.ok(Math.hypot(traced.stem.x, traced.stem.y) < 0.15, `stem at ${traced.stem.x}, ${traced.stem.y}`);
    assert.ok(traced.stemRadius >= stemClearance(keycap));
});

test('an L-shaped silhouette puts the stem inside the thick arm', () => {
    // A 120 px (12 mm) wide vertical arm on the left and a 40 px (4 mm) high foot along the bottom.
    const l = makeImage(300, 300, (x, y) =>
        (x >= 20 && x < 140 && y >= 20 && y < 280) || (x >= 20 && x < 280 && y >= 240 && y < 280) ? [29, 78, 216] : null,
    );
    const frame = frameFor(l);
    const traced = traceOutline({ image: l, frame, keycap });
    const px = (traced.stem.x - frame.left) / frame.mmPerPx;
    const py = (frame.top - traced.stem.y) / frame.mmPerPx;

    assert.ok(px > 20 && px < 140, `stem column ${px}`);
    assert.ok(py > 20 && py < 240, `stem row ${py}`);
});

test('a monogram stays fast: analyse and build under 1 s warm', () => {
    const settings = { ...SETTINGS, colors: BLACK, base_shape: 'trace' };
    const image = monogramArtwork();
    for (const quality of ['preview', 'final']) {
        buildParts(analyzeArtwork(image, settings, KEYCAP_BODY, quality), settings, KEYCAP_BODY);
        const started = performance.now();
        buildParts(analyzeArtwork(image, settings, KEYCAP_BODY, quality), settings, KEYCAP_BODY);
        const took = performance.now() - started;
        assert.ok(took < 1000, `${quality} took ${Math.round(took)} ms`);
    }
});
