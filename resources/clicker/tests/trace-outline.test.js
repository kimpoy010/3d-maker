import assert from 'node:assert/strict';
import { test } from 'node:test';
import { keycapConfig, stemClearance } from '../keycap.js';
import { extrudePolygons, isClosedManifold } from '../mesh.js';
import { HULL_NOTE, traceOutline } from '../trace-outline.js';
import { signedArea } from '../trace.js';
import { logoArtwork, makeImage } from './helpers.js';

const keycap = keycapConfig({});

/** The frame analyzeArtwork uses for Trace: the image fitted into a `size` square centred on the origin. */
function frameFor(image, size = 30) {
    const mmPerPx = Math.min(size / image.width, size / image.height);

    return { left: (-image.width * mmPerPx) / 2, top: (image.height * mmPerPx) / 2, mmPerPx };
}

function pointInRing([px, py], ring) {
    let inside = false;
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
        const [xi, yi] = ring[i];
        const [xj, yj] = ring[j];
        if (yi > py !== yj > py && px < ((xj - xi) * (py - yi)) / (yj - yi) + xi) inside = !inside;
    }

    return inside;
}

function distanceToRing([px, py], ring) {
    let best = Infinity;
    for (let i = 0; i < ring.length; i++) {
        const [ax, ay] = ring[i];
        const [bx, by] = ring[(i + 1) % ring.length];
        const dx = bx - ax;
        const dy = by - ay;
        const len = dx * dx + dy * dy;
        const t = len === 0 ? 0 : Math.max(0, Math.min(1, ((px - ax) * dx + (py - ay) * dy) / len));
        best = Math.min(best, Math.hypot(px - (ax + t * dx), py - (ay + t * dy)));
    }

    return best;
}

/** Centres (mm) of opaque pixels on the edge of the silhouette: an opaque pixel next to a clear one or the border. */
function edgeCentres(image, frame) {
    const { data, width, height } = image;
    const opaque = (x, y) => x >= 0 && y >= 0 && x < width && y < height && data[(y * width + x) * 4 + 3] >= 128;
    const out = [];
    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            if (!opaque(x, y)) continue;
            if (opaque(x - 1, y) && opaque(x + 1, y) && opaque(x, y - 1) && opaque(x, y + 1)) continue;
            out.push([frame.left + (x + 0.5) * frame.mmPerPx, frame.top - (y + 0.5) * frame.mmPerPx]);
        }
    }

    return out;
}

function assertGoodRing(outline) {
    assert.ok(outline.length >= 3);
    assert.ok(signedArea(outline) > 0, 'counter-clockwise');
    const [first, last] = [outline[0], outline[outline.length - 1]];
    assert.ok(first[0] !== last[0] || first[1] !== last[1], 'the first point is not repeated');
    const mesh = extrudePolygons([{ outer: outline, holes: [] }], 0, 1);
    assert.ok(isClosedManifold(mesh));
}

function assertContains(outline, points, margin) {
    for (const p of points) {
        assert.ok(pointInRing(p, outline), `${p} is inside the outline`);
        const d = distanceToRing(p, outline);
        assert.ok(d >= margin, `${p} is ${d.toFixed(3)} mm from the outline, want >= ${margin}`);
    }
}

const disc = (size, radius, rgb = [29, 78, 216]) =>
    makeImage(size, size, (x, y) => (Math.hypot(x + 0.5 - size / 2, y + 0.5 - size / 2) <= radius ? rgb : null));

test('a disc gives a round outline padded by the rim, with the stem in the middle', () => {
    const image = disc(240, 100);
    const frame = frameFor(image);
    const { outline, stem, stemRadius, notes } = traceOutline({ image, frame, keycap });

    assertGoodRing(outline);
    assertContains(outline, edgeCentres(image, frame), keycap.rim - 0.2);
    assert.deepEqual(notes, []);
    assert.ok(Math.hypot(stem.x, stem.y) < 0.3, `stem at ${stem.x}, ${stem.y}`);
    const radiusMm = 100 * frame.mmPerPx + keycap.rim;
    assert.ok(Math.abs(stemRadius - radiusMm) < 0.3, `stemRadius ${stemRadius} vs ${radiusMm}`);
    assert.ok(Math.abs(signedArea(outline) - Math.PI * radiusMm ** 2) / (Math.PI * radiusMm ** 2) < 0.03);
});

test('a soft-edged disc keeps the rim margin around every opaque pixel and centres the stem', () => {
    const size = 260;
    const image = makeImage(size, size, (x, y) => {
        const d = Math.hypot(x + 0.5 - size / 2, y + 0.5 - size / 2);
        const alpha = Math.round(255 * Math.max(0, Math.min(1, (106 - d) / 12)));

        return alpha > 0 ? [29, 78, 216, alpha] : null;
    });
    const frame = frameFor(image);
    const { outline, stem, notes } = traceOutline({ image, frame, keycap });

    assertGoodRing(outline);
    assertContains(outline, edgeCentres(image, frame), keycap.rim - 0.2);
    assert.deepEqual(notes, []);
    assert.ok(Math.hypot(stem.x, stem.y) < 0.3, `stem at ${stem.x}, ${stem.y}`);
});

/** A ring (outer 100, inner 70) centred at (130, 130) with a separate dot of radius 12 `gap` px to its right. */
const ringAndDot = (gap) =>
    makeImage(260 + gap + 30, 260, (x, y) => {
        const d = Math.hypot(x + 0.5 - 130, y + 0.5 - 130);
        if (d <= 100 && d >= 70) return [29, 78, 216];
        if (Math.hypot(x + 0.5 - (230 + gap + 12), y + 0.5 - 130) <= 12) return [220, 38, 38];

        return null;
    });

for (const gap of [8, 40]) {
    test(`a ring with a dot ${gap} px away closes into one filled outline without the hull`, () => {
        const image = ringAndDot(gap);
        const frame = frameFor(image);
        const { outline, stem, stemRadius, notes } = traceOutline({ image, frame, keycap });

        assertGoodRing(outline);
        assertContains(outline, edgeCentres(image, frame), keycap.rim - 0.2);
        assert.ok(!notes.includes(HULL_NOTE), notes.join(' | '));
        // Filled: the ring's centre is solid, and the stem sits in the filled middle.
        const centre = [frame.left + 130 * frame.mmPerPx, frame.top - 130 * frame.mmPerPx];
        assert.ok(pointInRing(centre, outline));
        assert.ok(stemRadius > 70 * frame.mmPerPx, `stemRadius ${stemRadius}`);
        assert.ok(Math.hypot(stem.x - centre[0], stem.y - centre[1]) < 70 * frame.mmPerPx);
    });
}

test('pieces too far apart to close are wrapped in their convex hull, with a note', () => {
    const image = makeImage(800, 100, (x) => (x < 100 || x >= 700 ? [29, 78, 216] : null));
    const frame = frameFor(image);
    const { outline, notes } = traceOutline({ image, frame, keycap });

    assertGoodRing(outline);
    assertContains(outline, edgeCentres(image, frame), keycap.rim - 0.2);
    assert.ok(notes.includes(HULL_NOTE));
    assert.equal(HULL_NOTE, 'The artwork has separate pieces, so the shape joins them with a wrapped outline.');
    assert.ok(pointInRing([0, 0], outline), 'the gap between the blocks is filled');
});

test('a thin line is too thin to hold the stem', () => {
    const image = makeImage(220, 220, (x, y) => (x >= 10 && x < 210 && y >= 108 && y < 112 ? [17, 17, 17] : null));
    const { outline, stemRadius } = traceOutline({ image, frame: frameFor(image), keycap });

    assertGoodRing(outline);
    assert.ok(stemRadius < stemClearance(keycap), `stemRadius ${stemRadius}`);
});

test('a fully opaque rectangle gives the rectangle grown by the rim', () => {
    const image = makeImage(300, 200, () => [255, 255, 255]);
    const frame = frameFor(image);
    const { outline, stem } = traceOutline({ image, frame, keycap });

    assertGoodRing(outline);
    assertContains(outline, edgeCentres(image, frame), keycap.rim - 0.2);
    const { rim } = keycap;
    const expected = (30 + 2 * rim) * (20 + 2 * rim) - (4 - Math.PI) * rim * rim;
    assert.ok(Math.abs(signedArea(outline) - expected) / expected < 0.03, `area ${signedArea(outline)} vs ${expected}`);
    assert.ok(Math.abs(stem.y) < 0.3);
});

test('a logo-like image (ring, monitor, touching dot, soft edges) gives one outline around everything', () => {
    const image = logoArtwork(500);
    const frame = frameFor(image);
    const { outline, stem, stemRadius, notes } = traceOutline({ image, frame, keycap });

    assertGoodRing(outline);
    assertContains(outline, edgeCentres(image, frame), keycap.rim - 0.2);
    assert.deepEqual(notes, []);
    assert.ok(pointInRing([stem.x, stem.y], outline));
    assert.ok(stemRadius >= stemClearance(keycap));
});

test('an empty image falls back to the artwork rectangle', () => {
    const image = makeImage(100, 100, () => null);
    const frame = frameFor(image);
    const { outline } = traceOutline({ image, frame, keycap });

    assertGoodRing(outline);
    // A 100 px image is 0.3 mm per pixel, so the grid is that coarse too.
    const corners = [
        [-14.85, -14.85],
        [14.85, 14.85],
    ];
    assertContains(outline, corners, keycap.rim - frame.mmPerPx);
});
