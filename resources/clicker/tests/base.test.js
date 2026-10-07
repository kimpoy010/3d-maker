import { strFromU8, unzipSync } from 'fflate';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { PNG } from 'pngjs';
import { analyzeArtwork, buildParts } from '../geometry.js';
import { baseFits, buildBase, capInsertion, circleOutline, growOutline, keycapConfig, squareOutline } from '../keycap.js';
import { bounds, extrudePolygons, isClosedManifold, meshVolume } from '../mesh.js';
import { pointInPolygons } from '../solids.js';
import { parseStl } from '../stl.js';
import { traceOutline } from '../trace-outline.js';
import { signedArea } from '../trace.js';
import { KEYCAP_BODY, SETTINGS, logoArtwork, makeImage, materialAlong, ownerLikeLogo } from './helpers.js';

const BUILD = fileURLToPath(new URL('../build.mjs', import.meta.url));
const FIT_BLOCKER = 'This shape is too small to hold the switch base. Make the artwork larger or choose Square or Circle.';
const FAILED_BLOCKER = 'The switch base could not be built for this shape. Make the artwork larger or choose Square or Circle.';
const EPS = 1e-3;

const keycap = keycapConfig({});
const {
    clearance: CLEARANCE,
    wall: WALL,
    plate: PLATE,
    under: UNDER,
    floor: FLOOR,
    cavity: CAVITY,
    cutout: CUT,
    rest_gap: REST,
    spacing: SPACING,
} = keycap.base;
const PLATE_TOP = UNDER + PLATE;
const topFor = (insertion) => PLATE_TOP + REST + insertion * keycap.height;
const TOP = topFor(0.5);

const near = (actual, expected, relative, label) =>
    assert.ok(Math.abs(actual - expected) <= Math.abs(expected) * relative, `${label}: ${actual} vs ${expected}`);

const area = (ring) => Math.abs(signedArea(ring));
const whole = (ring) => [{ outer: ring, holes: [] }];

const assertIntervals = (actual, expected, label) => {
    assert.equal(actual.length, expected.length, `${label}: ${JSON.stringify(actual)}`);
    actual.forEach(([a, b], i) => {
        assert.ok(Math.abs(a - expected[i][0]) < EPS && Math.abs(b - expected[i][1]) < EPS, `${label}: ${JSON.stringify(actual)}`);
    });
};

/** Distance from a point to a closed ring's edges. */
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

const squareAround = ({ x, y }, half) => [
    [x - half, y - half],
    [x + half, y - half],
    [x + half, y + half],
    [x - half, y + half],
];

/** A grid point that `accept`s, at least `margin` from every listed ring edge. */
function findPoint(rings, accept, margin = 0.3) {
    const vertices = rings.flat();
    const [x0, y0, x1, y1] = vertices.reduce(
        (b, [x, y]) => [Math.min(b[0], x), Math.min(b[1], y), Math.max(b[2], x), Math.max(b[3], y)],
        [Infinity, Infinity, -Infinity, -Infinity],
    );
    for (let y = y0 + 0.0137; y < y1; y += 0.11) {
        for (let x = x0 + 0.0213; x < x1; x += 0.11) {
            if (accept([x, y]) && rings.every((ring) => distanceToRing([x, y], ring) > margin)) return [x, y];
        }
    }
    throw new Error('no point found');
}

const analyse = (shape, image, extra = {}) => {
    const settings = { ...SETTINGS, base_shape: shape, ...extra };
    return { settings, analysis: analyzeArtwork(image, settings, KEYCAP_BODY, 'preview') };
};

const solidBlue = () => makeImage(40, 40, () => [29, 78, 216]);
const imageFor = (shape) => (shape === 'trace' ? logoArtwork(300) : solidBlue());

/**
 * Check the base against the spec. `dx`, `dz` move the base's own frame (z 0 at its bottom) into the part's frame.
 */
function checkBase(part, { baseOutline, pocketOutline, stem }, { dx = 0, dz = 0, top = TOP } = {}) {
    assert.equal(part.kind, 'base');
    assert.equal(part.name, 'Base');
    assert.equal(part.hex, '#6B7280');
    assert.ok(isClosedManifold(part), 'manifold');

    const expected = area(baseOutline) * top - area(pocketOutline) * (top - PLATE_TOP) - CAVITY * CAVITY * (UNDER - FLOOR) - CUT * CUT * PLATE;
    near(meshVolume(part), expected, 0.01, 'volume');

    const [, , z0, , , z1] = bounds(part);
    assert.ok(Math.abs(z0 - dz) < EPS && Math.abs(z1 - (top + dz)) < EPS, `z ${z0}..${z1}`);

    const ray = ([x, y]) => materialAlong(part, x + dx, y);
    const at = (intervals) => intervals.map(([a, b]) => [a + dz, b + dz]);
    const cavitySquare = squareAround(stem, CAVITY / 2);

    assertIntervals(ray([stem.x + 0.011, stem.y + 0.007]), at([[0, FLOOR]]), 'through the cutout: the floor only, then the pocket and the open top');
    const plate = [stem.x + (CUT + CAVITY) / 4, stem.y + 0.0071];
    assertIntervals(
        ray(plate),
        at([
            [0, FLOOR],
            [UNDER, PLATE_TOP],
        ]),
        'through the plate over the pocket',
    );
    const rim = findPoint(
        [baseOutline, pocketOutline, cavitySquare],
        (p) => pointInPolygons(p, whole(baseOutline)) && !pointInPolygons(p, whole(pocketOutline)) && !pointInPolygons(p, whole(cavitySquare)),
    );
    assertIntervals(ray(rim), at([[0, top]]), 'through the rim ring');
    const pocket = findPoint(
        [pocketOutline, cavitySquare],
        (p) => pointInPolygons(p, whole(pocketOutline)) && !pointInPolygons(p, whole(cavitySquare)),
    );
    assertIntervals(ray(pocket), at([[0, PLATE_TOP]]), 'through the pocket: nothing above the plate');

    // The bottom is closed: every probe point inside the outline meets material at z 0, and the face there is the
    // whole outline with no inner ring.
    for (const point of [[stem.x + 0.011, stem.y + 0.007], plate, rim, pocket]) assert.ok(Math.abs(ray(point)[0][0] - dz) < EPS, 'closed bottom');
    const bottomVertices = [];
    for (let i = 0; i < part.positions.length; i += 3) {
        if (Math.abs(part.positions[i + 2] - dz) < EPS) bottomVertices.push([part.positions[i] - dx, part.positions[i + 1]]);
    }
    const stemSquare = squareAround(stem, CAVITY / 2);
    assert.ok(bottomVertices.length > 0);
    assert.ok(
        bottomVertices.every((p) => distanceToRing(p, baseOutline) < EPS),
        'z 0 vertices are all on the outline',
    );
    assert.ok(!bottomVertices.some((p) => pointInPolygons(p, whole(stemSquare))), 'no vertex of the cavity square at z 0');
}

test('keycapConfig carries the new base defaults, deep merges them and ignores the old keys', () => {
    assert.deepEqual(keycap.base, {
        clearance: 0.3,
        wall: 2.0,
        plate: 1.5,
        under: 7.0,
        floor: 1.5,
        cavity: 16.2,
        cutout: 14.1,
        rest_gap: 8.0,
        spacing: 10,
        hex: '#6B7280',
    });
    const merged = keycapConfig({ keycap: { base: { rest_gap: 9 } } });
    assert.equal(merged.base.rest_gap, 9);
    assert.equal(merged.base.cutout, 14.1);

    // An old row that still carries height and gap builds the same base.
    const settings = { ...SETTINGS, base_shape: 'square' };
    const old = analyzeArtwork(solidBlue(), settings, { ...KEYCAP_BODY, keycap: { base: { height: 30, gap: 9 } } }, 'preview');
    const fresh = analyzeArtwork(solidBlue(), settings, KEYCAP_BODY, 'preview');
    assert.deepEqual(old.cap.baseBody.positions, fresh.cap.baseBody.positions);
});

test('capInsertion clamps to 0.5..1 and defaults to 0.5', () => {
    assert.equal(capInsertion({}), 0.5);
    assert.equal(capInsertion(undefined), 0.5);
    assert.equal(capInsertion({ cap_insertion: 0.75 }), 0.75);
    assert.equal(capInsertion({ cap_insertion: 1 }), 1);
    assert.equal(capInsertion({ cap_insertion: 0.2 }), 0.5);
    assert.equal(capInsertion({ cap_insertion: 3 }), 1);
    assert.equal(capInsertion({ cap_insertion: 'abc' }), 0.5);
    assert.equal(capInsertion({ cap_insertion: null }), 0.5);
    assert.equal(capInsertion({ cap_insertion: '0.8' }), 0.8, 'a numeric string');
    assert.equal(capInsertion({ cap_insertion: '2' }), 1);
    for (const junk of [true, false, NaN, Infinity, -Infinity, {}, [], [0.8], '', ' ', '0.8mm']) {
        assert.equal(capInsertion({ cap_insertion: junk }), 0.5, `${JSON.stringify(junk)} falls back`);
    }
});

test('growOutline grows the square and circle outlines by the distance, counter-clockwise', () => {
    for (const [shape, outline] of [
        ['square', squareOutline(keycap)],
        ['circle', circleOutline(keycap)],
    ]) {
        for (const distance of [CLEARANCE, CLEARANCE + WALL]) {
            const grown = growOutline(outline, shape, keycap, distance);
            assert.ok(signedArea(grown) > 0, `${shape} CCW`);
            near(Math.max(...grown.map((p) => p[0])), 15 + distance, 1e-9, `${shape} reach`);
            for (const point of grown) {
                assert.ok(!pointInPolygons(point, whole(outline)), `${shape} vertex outside the cap`);
                assert.ok(Math.abs(distanceToRing(point, outline) - distance) < 1e-6, `${shape} ${distanceToRing(point, outline)}`);
            }
            assert.ok(isClosedManifold(extrudePolygons(whole(grown), 0, 1)));
        }
    }
    const square = growOutline(squareOutline(keycap), 'square', keycap, 2);
    assert.deepEqual(square, squareOutline({ size: 34, corner_radius: 6 }));
    assert.deepEqual(growOutline(circleOutline(keycap), 'circle', keycap, 2), circleOutline({ size: 34 }));
});

test('the trace outline grows its mask by the distance, counter-clockwise', () => {
    const traced = traceOutline({ image: logoArtwork(200), frame: { left: -15, top: 15, mmPerPx: 30 / 200 }, keycap });
    const cell = 0.15; // 30 mm over 200 px
    for (const distance of [CLEARANCE, CLEARANCE + WALL]) {
        const grown = traced.grow(distance);
        assert.ok(signedArea(grown) > 0, 'CCW');
        assert.ok(isClosedManifold(extrudePolygons(whole(grown), 0, 1)));
        for (const point of grown) {
            assert.ok(!pointInPolygons(point, whole(traced.outline)), 'outside the cap outline');
            // Never closer than the distance (less the 0.03 mm simplification), at most about a cell diagonal farther.
            const d = distanceToRing(point, traced.outline);
            assert.ok(d >= distance - 0.03 && d <= distance + 1.5 * cell + 0.03, `grown vertex at ${d} for ${distance}`);
        }
        for (const point of traced.outline) {
            assert.ok(pointInPolygons(point, whole(grown)), 'the cap outline is inside');
            assert.ok(distanceToRing(point, grown) >= distance - 0.03, `cap vertex ${distanceToRing(point, grown)} from the grown ring`);
        }
    }
});

for (const shape of ['square', 'circle', 'trace']) {
    test(`${shape} base: one closed open-top shell with the cavity, plate, cutout and pocket`, () => {
        const { analysis } = analyse(shape, imageFor(shape));
        const { outline, stem, baseOutline, pocketOutline, baseBody } = analysis.cap;

        assert.deepEqual(analysis.blockers, []);
        assert.ok(signedArea(pocketOutline) > 0 && signedArea(baseOutline) > 0);
        assert.ok(area(pocketOutline) > area(outline) && area(baseOutline) > area(pocketOutline));
        assert.ok(baseFits({ cap: whole(outline), base: whole(baseOutline), stem, keycap }));
        checkBase(baseBody, analysis.cap);
    });

    test(`${shape} assembled layout: the cap sits in the pocket, its bottom ${REST} mm above the plate`, () => {
        for (const hollow of [false, true]) {
            const { analysis, settings } = analyse(shape, imageFor(shape), { hollow_cap: hollow });
            const parts = buildParts(analysis, settings, KEYCAP_BODY);
            assert.deepEqual(
                parts.slice(-2).map((p) => p.name),
                ['Cap', 'Base'],
                'the base comes last, after the cap',
            );
            const [cap, base] = parts.slice(-2);
            checkBase(base, analysis.cap, { dz: -(REST + PLATE_TOP) });
            const [, , bz0, , , bz1] = bounds(base);
            assert.ok(Math.abs(bz1 - 0.5 * keycap.height) < EPS, `rim at ${bz1}`);
            assert.ok(Math.abs(bz0 + REST + PLATE_TOP) < EPS);
            const [, , cz0, , , cz1] = bounds(cap);
            assert.ok(Math.abs(cz0) < EPS && Math.abs(cz1 - keycap.height) < EPS);

            // The cap outline plus the clearance fits within the pocket.
            const { outline, pocketOutline, stem } = analysis.cap;
            // Analytic: the pocket's chords cut its arcs by up to ~0.021 mm (4.3 mm corners in 8 segments); trace: the
            // 0.03 mm simplification.
            const slack = shape === 'trace' ? 0.03 : 0.025;
            for (const point of outline) {
                assert.ok(pointInPolygons(point, whole(pocketOutline)));
                assert.ok(distanceToRing(point, pocketOutline) >= CLEARANCE - slack, `${distanceToRing(point, pocketOutline)}`);
            }
            // No overlap: under the cap there is no base material above the plate top.
            for (const [fx, fy] of [
                [0.0004, 0.0003],
                [0.4, 0.1],
                [-0.3, 0.35],
                [0.1, -0.42],
                [-0.38, -0.2],
            ]) {
                const point = [stem.x + fx * 25, stem.y + fy * 25];
                if (!pointInPolygons(point, whole(outline))) continue;
                for (const [, z1] of materialAlong(base, ...point)) assert.ok(z1 <= -REST + EPS, `base material up to ${z1} under the cap`);
            }
        }
    });

    test(`${shape} print layout: the base stands upright beside the cap, clear by the spacing`, () => {
        const { analysis, settings } = analyse(shape, imageFor(shape));
        const printed = buildParts(analysis, settings, KEYCAP_BODY, { layout: 'print' });
        const assembled = buildParts(analysis, settings, KEYCAP_BODY);
        const base = printed.at(-1);
        const xs = analysis.cap.baseOutline.map((p) => p[0]);
        const shift = Math.max(...xs) - Math.min(...xs) + SPACING;

        checkBase(base, analysis.cap, { dx: shift });
        const [bx0] = bounds(base);
        near(bx0, Math.max(...xs) + SPACING, 1e-6, 'starts one spacing past the base outline');
        const capRight = Math.max(...printed.slice(0, -1).map((p) => bounds(p)[3]));
        assert.ok(bx0 >= capRight + SPACING - EPS, `base starts at x ${bx0}, the cap ends at ${capRight}`);
        near(meshVolume(base), meshVolume(assembled.at(-1)), 1e-6, 'same volume as assembled');
        for (let i = 0; i < printed.length - 1; i++) assert.deepEqual(printed[i].positions, assembled[i].positions, `${printed[i].name} unchanged`);
    });
}

test('cap_insertion 0.5 and 1.0 set the rim height', () => {
    for (const insertion of [0.5, 1]) {
        const { analysis, settings } = analyse('square', solidBlue(), { cap_insertion: insertion });
        checkBase(analysis.cap.baseBody, analysis.cap, { top: topFor(insertion) });
        const base = buildParts(analysis, settings, KEYCAP_BODY).at(-1);
        assert.ok(Math.abs(bounds(base)[5] - insertion * keycap.height) < EPS, `rim at ${bounds(base)[5]}`);
        const printed = buildParts(analysis, settings, KEYCAP_BODY, { layout: 'print' }).at(-1);
        assert.ok(Math.abs(bounds(printed)[2]) < EPS && Math.abs(bounds(printed)[5] - topFor(insertion)) < EPS);
    }
    assert.equal(topFor(1) - topFor(0.5), keycap.height / 2);
});

test('a different cap_insertion at build time rebuilds the base; an equal one reuses the analysed base', () => {
    const { analysis, settings } = analyse('square', solidBlue(), { cap_insertion: 0.5 });
    assert.equal(analysis.cap.baseInsertion, 0.5);
    const cached = analysis.cap.baseBody;

    const deeper = buildParts(analysis, { ...settings, cap_insertion: 1 }, KEYCAP_BODY).at(-1);
    assert.ok(Math.abs(bounds(deeper)[5] - keycap.height) < EPS, `rim at ${bounds(deeper)[5]}`);
    checkBase(deeper, analysis.cap, { dz: -(REST + PLATE_TOP), top: topFor(1) });
    const printed = buildParts(analysis, { ...settings, cap_insertion: 1 }, KEYCAP_BODY, { layout: 'print' }).at(-1);
    assert.ok(Math.abs(bounds(printed)[5] - topFor(1)) < EPS);
    assert.equal(analysis.cap.baseBody, cached, 'the analysis is left untouched');
    assert.equal(analysis.cap.baseInsertion, 0.5);

    // Equal insertion (also when the setting is missing, i.e. the default): the analysed base is used as is.
    analysis.cap.baseBody = { ...cached, hex: '#123456' };
    for (const same of [settings, { ...settings, cap_insertion: undefined }])
        assert.equal(buildParts(analysis, same, KEYCAP_BODY).at(-1).hex, '#123456');
    assert.equal(buildParts(analysis, { ...settings, cap_insertion: 0.75 }, KEYCAP_BODY).at(-1).hex, '#6B7280', 'rebuilt');
});

test('the base from the analysis is reused when only heights change', () => {
    const { analysis, settings } = analyse('trace', logoArtwork(300));
    const taller = { ...settings, colors: settings.colors.map((c) => ({ ...c, height_mm: c.height_mm + 0.5 })) };
    const a = buildParts(analysis, settings, KEYCAP_BODY, { layout: 'print' });
    const b = buildParts(analysis, taller, KEYCAP_BODY, { layout: 'print' });

    assert.deepEqual(b.at(-1).positions, a.at(-1).positions);
    assert.deepEqual(b.at(-1).indices, a.at(-1).indices);
    assert.notDeepEqual(bounds(b[0]), bounds(a[0]));
    // The analysis keeps the unplaced, upright base; layouts never change it.
    const [, , z0, , , z1] = bounds(analysis.cap.baseBody);
    assert.ok(Math.abs(z0) < EPS && Math.abs(z1 - TOP) < EPS);
});

test('an owner-like logo (opaque white background, ring around a monitor) is not blocked in Trace at scale 1', () => {
    for (const hollow of [false, true]) {
        const { analysis, settings } = analyse('trace', ownerLikeLogo(500), { hollow_cap: hollow });
        assert.deepEqual(analysis.blockers, [], `hollow ${hollow}`);
        const parts = buildParts(analysis, settings, KEYCAP_BODY);
        assert.ok(parts.some((p) => p.kind === 'colour'));
        checkBase(parts.at(-1), analysis.cap, { dz: -(REST + PLATE_TOP) });
        assert.ok(isClosedManifold(parts.at(-2)));
    }
});

test('a 6 mm blob in Trace is too small for the switch base; buildParts refuses it', () => {
    // A 3 mm radius dot fitted into 30 mm: the outline is a ~10 mm disc that holds the stem but not the switch.
    const dot = makeImage(300, 300, (x, y) => (Math.hypot(x + 0.5 - 150, y + 0.5 - 150) < 30 ? [29, 78, 216] : null));
    const { analysis, settings } = analyse('trace', dot);

    assert.deepEqual(analysis.blockers, [FIT_BLOCKER]);
    assert.throws(() => buildParts(analysis, settings, KEYCAP_BODY), { message: FIT_BLOCKER });
    assert.throws(() => buildParts(analysis, settings, KEYCAP_BODY, { layout: 'print' }), { message: FIT_BLOCKER });
});

test('a square keycap too small for the switch gets the same blocker', () => {
    const body = { ...KEYCAP_BODY, keycap: { size: 16 } };
    const settings = { ...SETTINGS, base_shape: 'square' };
    const analysis = analyzeArtwork(solidBlue(), settings, body, 'preview');

    assert.deepEqual(analysis.blockers, [FIT_BLOCKER]);
    assert.throws(() => buildParts(analysis, settings, body), { message: FIT_BLOCKER });
});

test('baseFits: the cutout plus 1.6 mm inside the cap outline, the cavity inside the base outline', () => {
    const square = (size) => whole(squareOutline({ size, corner_radius: 0 }));
    const stem = { x: 0, y: 0 };
    const need = CUT + 1.6;
    const roomy = square(40);

    assert.ok(baseFits({ cap: square(need + 0.2), base: roomy, stem, keycap }));
    assert.ok(!baseFits({ cap: square(need - 0.2), base: roomy, stem, keycap }), 'cap too tight');
    assert.ok(baseFits({ cap: square(need + 0.2), base: square(CAVITY + 0.2), stem, keycap }));
    assert.ok(!baseFits({ cap: square(need + 0.2), base: square(CAVITY - 0.2), stem, keycap }), 'cavity does not fit the base');
    assert.ok(!baseFits({ cap: square(40), base: roomy, stem: { x: 13, y: 0 }, keycap }), 'off to one side');
    assert.ok(!baseFits({ cap: [], base: roomy, stem, keycap }), 'no cap outline');

    const island = (cx, cy) => [
        [cx - 0.5, cy - 0.5],
        [cx - 0.5, cy + 0.5],
        [cx + 0.5, cy + 0.5],
        [cx + 0.5, cy - 0.5],
    ];
    const withIsland = (cx, cy) => [{ outer: squareOutline({ size: 40, corner_radius: 2 }), holes: [island(cx, cy)] }];
    assert.ok(baseFits({ cap: withIsland(15, 15), base: roomy, stem, keycap }), 'a far hole is fine');
    assert.ok(!baseFits({ cap: withIsland(0, 3), base: roomy, stem, keycap }), 'a hole inside the square');
    assert.ok(!baseFits({ cap: withIsland(need / 2, 3), base: roomy, stem, keycap }), 'a hole touching the square edge');

    // A notch reaching into the square between the sampled corners and midpoints.
    const notched = whole([
        [-20, -20],
        [20, -20],
        [20, 20],
        [4, 20],
        [3.5, 4],
        [3, 20],
        [-20, 20],
    ]);
    assert.ok(!baseFits({ cap: notched, base: roomy, stem, keycap }), 'a cap edge crossing the square');
    assert.ok(!baseFits({ cap: roomy, base: notched, stem, keycap }), 'a base edge crossing the cavity');
});

test('a base that cannot be built is never returned; the analysis gets the build blocker', () => {
    const outline = squareOutline({ size: 34.6, corner_radius: 6.3 });
    const pocket = squareOutline({ size: 30.6, corner_radius: 4.3 });
    const stem = { x: 0, y: 0 };
    const base = buildBase({ pocket, outline, stem, keycap, insertion: 0.5 });
    assert.ok(isClosedManifold(base));

    assert.equal(buildBase({ pocket, outline, stem: { x: 10, y: 0 }, keycap, insertion: 0.5 }), null, 'the squares leave the outlines');
    const wide = { ...keycap, base: { ...keycap.base, cutout: 17 } };
    assert.equal(buildBase({ pocket, outline, stem, keycap: wide, insertion: 0.5 }), null, 'the cutout is wider than the cavity');

    const body = { ...KEYCAP_BODY, keycap: { base: { cutout: 17 } } };
    const settings = { ...SETTINGS, base_shape: 'square' };
    const analysis = analyzeArtwork(solidBlue(), settings, body, 'preview');
    assert.deepEqual(analysis.blockers, [FAILED_BLOCKER]);
    assert.equal(analysis.cap.baseBody, null);
    assert.throws(() => buildParts(analysis, settings, body), { message: FAILED_BLOCKER });
});

test('floor defaults to 1.5 when missing; an old under of 6.0 still builds; under <= floor or floor <= 0 cannot be built', () => {
    const outline = squareOutline({ size: 34.6, corner_radius: 6.3 });
    const pocket = squareOutline({ size: 30.6, corner_radius: 4.3 });
    const stem = { x: 0, y: 0 };
    const withBase = (base) => ({ ...keycap, base: { ...keycap.base, ...base } });

    const row = keycapConfig({ keycap: { base: { clearance: 0.3, wall: 2.0, plate: 1.5, under: 6.0, cavity: 16.2, cutout: 14.1 } } });
    assert.equal(row.base.floor, 1.5);
    const legacy = buildBase({ pocket, outline, stem, keycap: row, insertion: 0.5 });
    assert.ok(isClosedManifold(legacy));
    near(bounds(legacy)[5], 6 + PLATE + REST + 0.5 * keycap.height, 1e-9, 'legacy rim');
    // Pocket 6.0 - 1.5 = 4.5 tall.
    const lifted = materialAlong(legacy, stem.x + (CUT + CAVITY) / 4, stem.y + 0.0071);
    assertIntervals(
        lifted,
        [
            [0, 1.5],
            [6, 7.5],
        ],
        'legacy plate over a 4.5 mm pocket',
    );

    for (const bad of [{ floor: 0 }, { floor: -1 }, { under: 1.5 }, { under: 1 }]) {
        assert.equal(buildBase({ pocket, outline, stem, keycap: withBase(bad), insertion: 0.5 }), null, JSON.stringify(bad));
    }
    const body = { ...KEYCAP_BODY, keycap: { base: { under: 1.5 } } };
    const settings = { ...SETTINGS, base_shape: 'square' };
    const analysis = analyzeArtwork(solidBlue(), settings, body, 'preview');
    assert.deepEqual(analysis.blockers, [FAILED_BLOCKER]);
});

test('legacy bodies get no base in either layout', async () => {
    const { BODY } = await import('./helpers.js');
    const analysis = analyzeArtwork(logoArtwork(200), SETTINGS, BODY, 'preview');
    for (const layout of ['assembled', 'print']) {
        assert.ok(buildParts(analysis, SETTINGS, BODY, { layout }).every((p) => p.kind === 'colour' || p.kind === 'outline'));
    }
});

function writeJob(image, settings, body) {
    const dir = mkdtempSync(join(tmpdir(), 'clicker-job-'));
    const png = new PNG({ width: image.width, height: image.height });
    png.data = Buffer.from(image.data);
    writeFileSync(join(dir, 'artwork.png'), PNG.sync.write(png));
    writeFileSync(join(dir, 'job.json'), JSON.stringify({ settings, body, title: 'Keycap', folder: 'clicker-1' }));

    return dir;
}

test('build.mjs writes Cap and Base, the base upright beside the cap', () => {
    const dir = writeJob(ownerLikeLogo(500), { ...SETTINGS, base_shape: 'trace', hollow_cap: true }, KEYCAP_BODY);
    const run = spawnSync(process.execPath, [BUILD, dir], { encoding: 'utf8' });

    assert.equal(run.status, 0, run.stderr);
    const model = strFromU8(unzipSync(readFileSync(join(dir, 'clicker.3mf')))['3D/3dmodel.model']);
    const names = [...model.matchAll(/<object [^>]*name="([^"]*)"/g)].map((m) => m[1]);
    assert.deepEqual(names.slice(-2), ['Cap', 'Base']);
    assert.equal(names.filter((n) => n === 'Base').length, 1);

    const zip = unzipSync(readFileSync(join(dir, 'clicker-stl.zip')));
    const files = Object.keys(zip).filter((f) => f.endsWith('.stl'));
    const baseFile = files.find((f) => /base/.test(f));
    const capFile = files.find((f) => /cap/.test(f));
    assert.ok(baseFile, files.join(', '));
    assert.match(baseFile, /\/\d\d-base\.stl$/);
    assert.match(capFile, /\/\d\d-cap\.stl$/);
    const readme = strFromU8(zip[Object.keys(zip).find((f) => /README/i.test(f))]);
    assert.match(readme, /upright with its open top up/);

    const base = parseStl(zip[baseFile]);
    const cap = parseStl(zip[capFile]);
    assert.ok(isClosedManifold(base));
    const [bx0, , bz0, , , bz1] = bounds(base);
    assert.ok(Math.abs(bz0) < EPS && Math.abs(bz1 - TOP) < EPS, `upright, z ${bz0}..${bz1}`);
    assert.ok(bx0 >= bounds(cap)[3] + SPACING - EPS, 'beside the cap');

    const result = JSON.parse(readFileSync(join(dir, 'result.json'), 'utf8'));
    assert.deepEqual(
        result.parts.slice(-2).map((p) => p.kind),
        ['body', 'base'],
    );
});

test('build.mjs still says no printable colour when only the cap and base are there', () => {
    const dir = writeJob(
        makeImage(20, 20, () => null),
        { ...SETTINGS, base_shape: 'square' },
        KEYCAP_BODY,
    );
    const run = spawnSync(process.execPath, [BUILD, dir], { encoding: 'utf8' });

    assert.equal(run.status, 1);
    assert.match(run.stderr, /no printable colour/);
});
