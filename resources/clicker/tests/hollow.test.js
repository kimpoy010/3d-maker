import assert from 'node:assert/strict';
import { test } from 'node:test';
import { analyzeArtwork, buildParts, prepareHollowCap } from '../geometry.js';
import { buildHollowCapBody, buildKeycapBody, hollowCavity, insetOutline, keycapConfig, squareOutline } from '../keycap.js';
import { bounds, extrudePolygons, isClosedManifold, meshVolume } from '../mesh.js';
import { pointInPolygons, verifiedMesh } from '../solids.js';
import { signedArea } from '../trace.js';
import { KEYCAP_BODY, SETTINGS, logoArtwork, makeImage, materialAlong } from './helpers.js';

const HOLLOW_NOTE = 'The shape is too small for a hollow cap, so the cap is solid.';
const FAILED_NOTE = 'The hollow cap could not be built for this shape, so the cap is solid.';
const EPS = 1e-3;

const near = (actual, expected, relative, label) =>
    assert.ok(Math.abs(actual - expected) <= Math.abs(expected) * relative, `${label}: ${actual} vs ${expected}`);

const polygonArea = (polygons) =>
    polygons.reduce((sum, { outer, holes }) => sum + Math.abs(signedArea(outer)) - holes.reduce((s, h) => s + Math.abs(signedArea(h)), 0), 0);

const assertIntervals = (actual, expected, label) => {
    assert.equal(actual.length, expected.length, `${label}: ${JSON.stringify(actual)}`);
    actual.forEach(([a, b], i) => {
        assert.ok(Math.abs(a - expected[i][0]) < EPS && Math.abs(b - expected[i][1]) < EPS, `${label}: ${JSON.stringify(actual)}`);
    });
};

/** A point well inside the cavity: on a grid, inside the polygons and at least `margin` from every vertex. */
function cavityPoint(cavity, margin = 0.3) {
    const vertices = cavity.flatMap(({ outer, holes }) => [outer, ...holes]).flat();
    const [x0, y0, x1, y1] = vertices.reduce(
        (b, [x, y]) => [Math.min(b[0], x), Math.min(b[1], y), Math.max(b[2], x), Math.max(b[3], y)],
        [Infinity, Infinity, -Infinity, -Infinity],
    );
    for (let y = y0 + 0.0137; y < y1; y += 0.25) {
        for (let x = x0 + 0.0213; x < x1; x += 0.25) {
            if (!pointInPolygons([x, y], cavity)) continue;
            if (vertices.every(([vx, vy]) => Math.hypot(vx - x, vy - y) > margin)) return [x, y];
        }
    }
    throw new Error('no cavity point');
}

function checkHollow(body, { outline, cavity, stem, keycap }) {
    const { height: H, socket_depth: depth } = keycap;
    const { plate } = keycap.hollow;
    const { span, arm } = keycap.stem;
    const crossArea = 2 * span * arm - arm * arm;

    assert.equal(body.kind, 'body');
    assert.equal(body.name, 'Cap');
    assert.equal(body.hex, '#9CA3AF');
    assert.ok(isClosedManifold(body), 'manifold');

    const expected = Math.abs(signedArea(outline)) * H - polygonArea(cavity) * (H - plate) - crossArea * depth;
    near(meshVolume(body), expected, 0.01, 'volume');

    const [, , z0, , , z1] = bounds(body);
    assert.ok(Math.abs(z0) < EPS && Math.abs(z1 - H) < EPS, `z ${z0}..${z1}`);

    assertIntervals(materialAlong(body, stem.x + 0.011, stem.y + 0.007), [[depth, H]], 'stem ray');
    const [cx, cy] = cavityPoint(cavity);
    assertIntervals(materialAlong(body, cx, cy), [[H - plate, H]], 'cavity ray');
}

const keycap = keycapConfig({});

/** The cap part; the switch base follows it. */
const capOf = (parts) => {
    const caps = parts.filter((p) => p.name === 'Cap');
    assert.equal(caps.length, 1);
    assert.equal(parts.at(-1).name, 'Base');
    return caps[0];
};

test('keycapConfig carries the hollow defaults', () => {
    assert.deepEqual(keycap.hollow, { wall: 1.6, plate: 2.0, boss_radius: 3.6 });
    assert.equal(keycapConfig({ keycap: { hollow: { wall: 2 } } }).hollow.plate, 2.0);
});

test('insetOutline is analytic for square and circle', () => {
    const [square] = insetOutline(null, 'square', keycap, 1.6);
    assert.deepEqual(square.holes, []);
    near(Math.abs(signedArea(square.outer)), Math.abs(signedArea(squareOutline({ size: 30 - 3.2, corner_radius: 4 - 1.6 }))), 1e-9, 'square');
    assert.ok(signedArea(square.outer) > 0);

    const [tight] = insetOutline(null, 'square', { ...keycap, corner_radius: 1 }, 1.6);
    const xs = tight.outer.map((p) => p[0]);
    assert.ok(Math.abs(Math.max(...xs) - 13.4) < 1e-9);
    near(Math.abs(signedArea(tight.outer)), Math.abs(signedArea(squareOutline({ size: 26.8, corner_radius: 0.5 }))), 1e-9, 'radius clamped to 0.5');

    const [circle] = insetOutline(null, 'circle', keycap, 1.6);
    for (const [x, y] of circle.outer) near(Math.hypot(x, y), 13.4, 1e-9, 'circle radius');
});

for (const shape of ['square', 'circle']) {
    test(`hollow ${shape} cap: one closed shell with the cavity, boss and socket`, () => {
        const settings = { ...SETTINGS, base_shape: shape, hollow_cap: true };
        const analysis = analyzeArtwork(
            makeImage(40, 40, () => [29, 78, 216]),
            settings,
            KEYCAP_BODY,
            'preview',
        );
        const { cavity, outline, stem } = analysis.cap;

        assert.equal(cavity.length, 1);
        assert.equal(cavity[0].holes.length, 1, 'the boss island');
        assert.ok(signedArea(cavity[0].outer) > 0 && signedArea(cavity[0].holes[0]) < 0);
        assert.equal(cavity[0].holes[0].length, 48);
        near(Math.abs(signedArea(cavity[0].outer)), Math.abs(signedArea(insetOutline(outline, shape, keycap, 1.6)[0].outer)), 1e-9, 'inset area');
        near(Math.abs(signedArea(cavity[0].holes[0])), Math.PI * 3.6 ** 2, 0.01, 'boss area');
        assert.ok(!analysis.notes.includes(HOLLOW_NOTE));

        const parts = buildParts(analysis, settings, KEYCAP_BODY);
        checkHollow(capOf(parts), { outline, cavity, stem, keycap });
    });
}

test('hollow trace cap on a logo', () => {
    const settings = { ...SETTINGS, base_shape: 'trace', hollow_cap: true };
    const analysis = analyzeArtwork(logoArtwork(300), settings, KEYCAP_BODY, 'preview');
    const { cavity, outline, stem } = analysis.cap;

    assert.deepEqual(analysis.blockers, []);
    assert.ok(cavity.length >= 1);
    for (const { outer, holes } of cavity) {
        assert.ok(signedArea(outer) > 0);
        for (const hole of holes) assert.ok(signedArea(hole) < 0);
    }
    assert.ok(polygonArea(cavity) > 100, `cavity area ${polygonArea(cavity)}`);
    assert.ok(!pointInPolygons([stem.x, stem.y], cavity), 'the stem is not in the cavity');

    const parts = buildParts(analysis, settings, KEYCAP_BODY);
    checkHollow(capOf(parts), { outline, cavity, stem, keycap });
});

test('a cavity whose boss merged with the wall puts the cross in the bottom face', () => {
    const outline = squareOutline(keycap);
    const cavity = [
        {
            outer: [
                [2, -10],
                [12, -10],
                [12, 10],
                [2, 10],
            ],
            holes: [],
        },
    ];
    const stem = { x: -5, y: 1 };

    checkHollow(buildHollowCapBody({ outline, cavity, stem, keycap }), { outline, cavity, stem, keycap });
});

test('an island that does not hold the stem stays solid, the one that does gets the cross', () => {
    const outline = squareOutline(keycap);
    const ring = (cx, cy, r) =>
        Array.from({ length: 24 }, (_, i) => [cx + r * Math.cos((-2 * Math.PI * i) / 24), cy + r * Math.sin((-2 * Math.PI * i) / 24)]);
    const cavity = [{ outer: squareOutline({ size: 26, corner_radius: 2 }), holes: [ring(0, 0, 3.6), ring(7, 7, 2)] }];
    const stem = { x: 0, y: 0 };

    checkHollow(buildHollowCapBody({ outline, cavity, stem, keycap }), { outline, cavity, stem, keycap });
});

test('hollow_cap false keeps the solid cap, now named Cap, with the same volume', () => {
    for (const shape of ['square', 'trace']) {
        const settings = { ...SETTINGS, base_shape: shape };
        const analysis = analyzeArtwork(logoArtwork(300), settings, KEYCAP_BODY, 'preview');
        const parts = buildParts(analysis, settings, KEYCAP_BODY);
        const cap = capOf(parts);
        const solid = buildKeycapBody({ outline: analysis.cap.outline, stem: analysis.cap.stem, keycap });

        assert.equal(analysis.cap.cavity, undefined);
        assert.equal(cap.name, 'Cap');
        assert.equal(solid.name, 'Cap');
        near(meshVolume(cap), meshVolume(solid), 1e-9, `${shape} volume`);
        const { span, arm } = keycap.stem;
        near(meshVolume(cap), Math.abs(signedArea(analysis.cap.outline)) * 8 - (2 * span * arm - arm * arm) * 4.5, 0.005, `${shape} solid volume`);
    }
});

test('a trace shape too small for a cavity falls back to the solid cap with a note', () => {
    // A shape small enough for nothing to remain inside the boss is also too small for the switch base, so the
    // cavity is squeezed out by a 14 mm cavity wall instead: the ~32 mm logo outline erodes to a core inside the boss.
    const body = { ...KEYCAP_BODY, keycap: { hollow: { wall: 14 } } };
    const settings = { ...SETTINGS, base_shape: 'trace', hollow_cap: true };
    const analysis = analyzeArtwork(logoArtwork(300), settings, body, 'preview');

    assert.deepEqual(analysis.blockers, []);
    assert.deepEqual(analysis.cap.cavity, []);
    assert.ok(analysis.notes.includes(HOLLOW_NOTE));
    const parts = buildParts(analysis, settings, body);
    const cap = capOf(parts);
    assert.equal(cap.name, 'Cap');
    assert.ok(isClosedManifold(cap));
    const { span, arm } = keycap.stem;
    near(meshVolume(cap), Math.abs(signedArea(analysis.cap.outline)) * 8 - (2 * span * arm - arm * arm) * 4.5, 0.005, 'solid volume');
});

test('hollowCavity is empty when the inset leaves no room around the boss', () => {
    const small = { ...keycap, size: 10 };
    assert.deepEqual(hollowCavity({ shape: 'square', outline: squareOutline(small), stem: { x: 0, y: 0 }, keycap: small }), []);
});

test('the trace outline exposes cavity and inset', async () => {
    const { traceOutline } = await import('../trace-outline.js');
    const image = logoArtwork(200);
    const traced = traceOutline({ image, frame: { left: -15, top: 15, mmPerPx: 30 / 200 }, keycap });
    const inset = traced.inset(1.6);
    const cavity = traced.cavity(1.6, 3.6);
    const outlineArea = Math.abs(signedArea(traced.outline));

    assert.ok(inset.length >= 1);
    assert.ok(polygonArea(inset) < outlineArea && polygonArea(inset) > outlineArea * 0.6);
    assert.ok(polygonArea(cavity) < polygonArea(inset) - Math.PI * 3.6 ** 2 + 1, 'the boss disc is removed');
    for (const piece of [...inset, ...cavity]) assert.ok(polygonArea([piece]) >= 4);
    // The inset keeps at least about `wall` from the outline.
    for (const { outer } of inset) {
        for (const [x, y] of outer) {
            const nearest = Math.min(
                ...traced.outline.map((a, i) => {
                    const b = traced.outline[(i + 1) % traced.outline.length];
                    const dx = b[0] - a[0];
                    const dy = b[1] - a[1];
                    const t = Math.max(0, Math.min(1, ((x - a[0]) * dx + (y - a[1]) * dy) / (dx * dx + dy * dy)));

                    return Math.hypot(x - a[0] - t * dx, y - a[1] - t * dy);
                }),
            );
            assert.ok(nearest > 1.6 - 0.2, `inset vertex ${nearest} mm from the outline`);
        }
    }
});

test('verifiedMesh keeps closed positive shells only', () => {
    const box = extrudePolygons([{ outer: squareOutline({ size: 10, corner_radius: 0 }), holes: [] }], 0, 1);
    assert.equal(verifiedMesh(box), box);
    assert.equal(verifiedMesh({ positions: box.positions, indices: box.indices.slice(3) }), null, 'open');
    const inverted = Uint32Array.from(box.indices);
    for (let t = 0; t < inverted.length; t += 3) [inverted[t + 1], inverted[t + 2]] = [inverted[t + 2], inverted[t + 1]];
    assert.equal(verifiedMesh({ positions: box.positions, indices: inverted }), null, 'negative volume');
    assert.equal(verifiedMesh(null), null);
});

// An island touching the cavity's outer ring at one vertex: the vertical edge there is shared by four wall triangles.
function touchingCavity() {
    const disc = Array.from({ length: 48 }, (_, i) => [3.6 * Math.cos((-2 * Math.PI * i) / 48), 3.6 * Math.sin((-2 * Math.PI * i) / 48)]);
    return [
        {
            outer: [
                [-12, -12],
                [12, -12],
                [12, 0],
                [12, 12],
                [-12, 12],
            ],
            holes: [
                disc,
                [
                    [12, 0],
                    [8, -2],
                    [8, 2],
                ],
            ],
        },
    ];
}

test('a hollow shell that fails verification is never returned; the analysis falls back to the solid cap with a note', () => {
    const outline = squareOutline(keycap);
    const stem = { x: 0, y: 0 };
    assert.equal(buildHollowCapBody({ outline, cavity: touchingCavity(), stem, keycap }), null);

    const settings = { ...SETTINGS, base_shape: 'square', hollow_cap: true };
    const analysis = analyzeArtwork(
        makeImage(40, 40, () => [29, 78, 216]),
        settings,
        KEYCAP_BODY,
        'preview',
    );
    assert.ok(analysis.cap.hollowBody, 'the regular square builds hollow');
    assert.ok(isClosedManifold(analysis.cap.hollowBody));

    analysis.cap.cavity = touchingCavity();
    prepareHollowCap(analysis.cap, keycap, analysis.notes);
    assert.equal(analysis.cap.hollowBody, null);
    assert.ok(analysis.notes.includes(FAILED_NOTE));

    const parts = buildParts(analysis, settings, KEYCAP_BODY);
    const cap = capOf(parts);
    assert.equal(cap.name, 'Cap');
    assert.ok(isClosedManifold(cap));
    near(meshVolume(cap), meshVolume(buildKeycapBody({ outline, stem, keycap })), 1e-9, 'solid volume');
});

test('the hollow cap from the analysis is reused when only heights change', () => {
    const settings = { ...SETTINGS, base_shape: 'circle', hollow_cap: true };
    const analysis = analyzeArtwork(
        makeImage(40, 40, () => [29, 78, 216]),
        settings,
        KEYCAP_BODY,
        'preview',
    );
    const taller = { ...settings, colors: settings.colors.map((c) => ({ ...c, height_mm: c.height_mm + 0.5 })) };
    const a = buildParts(analysis, settings, KEYCAP_BODY);
    const b = buildParts(analysis, taller, KEYCAP_BODY);

    assert.deepEqual(capOf(b).positions, capOf(a).positions);
    assert.notDeepEqual(bounds(b[0]), bounds(a[0]), 'the colour parts follow the new heights');
    near(meshVolume(capOf(b)), meshVolume(analysis.cap.hollowBody), 1e-9, 'hollow volume');
    assert.ok(
        meshVolume(capOf(buildParts(analysis, { ...settings, hollow_cap: false }, KEYCAP_BODY))) > meshVolume(capOf(a)),
        'off gives the solid cap',
    );
});

test('config guards: a plate as tall as the cap or a boss too small for the cross gives no cavity and the note', () => {
    const square = (config) => {
        const k = keycapConfig({ keycap: config });
        return hollowCavity({ shape: 'square', outline: squareOutline(k), stem: { x: 0, y: 0 }, keycap: k });
    };
    assert.equal(square({}).length, 1);
    assert.deepEqual(square({ hollow: { plate: 8 } }), []);
    assert.deepEqual(square({ hollow: { plate: 9 } }), []);
    const reach = Math.hypot(4.2 / 2, 1.35 / 2);
    assert.deepEqual(square({ hollow: { boss_radius: reach + 0.49 } }), []);
    assert.equal(square({ hollow: { boss_radius: reach + 0.51 } }).length, 1);

    const body = { ...KEYCAP_BODY, keycap: { hollow: { plate: 8 } } };
    const settings = { ...SETTINGS, base_shape: 'trace', hollow_cap: true };
    const analysis = analyzeArtwork(logoArtwork(200), settings, body, 'preview');
    assert.deepEqual(analysis.cap.cavity, []);
    assert.ok(analysis.notes.includes(HOLLOW_NOTE));
    assert.equal(capOf(buildParts(analysis, settings, body)).name, 'Cap');
});
