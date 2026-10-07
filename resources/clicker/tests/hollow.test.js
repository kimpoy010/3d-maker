import assert from 'node:assert/strict';
import { test } from 'node:test';
import { analyzeArtwork, buildParts } from '../geometry.js';
import { buildHollowCapBody, buildKeycapBody, hollowCavity, insetOutline, keycapConfig, squareOutline } from '../keycap.js';
import { bounds, isClosedManifold, meshVolume } from '../mesh.js';
import { pointInPolygons } from '../solids.js';
import { signedArea } from '../trace.js';
import { KEYCAP_BODY, SETTINGS, logoArtwork, makeImage } from './helpers.js';

const HOLLOW_NOTE = 'The shape is too small for a hollow cap, so the cap is solid.';
const EPS = 1e-3;

const near = (actual, expected, relative, label) =>
    assert.ok(Math.abs(actual - expected) <= Math.abs(expected) * relative, `${label}: ${actual} vs ${expected}`);

const polygonArea = (polygons) =>
    polygons.reduce((sum, { outer, holes }) => sum + Math.abs(signedArea(outer)) - holes.reduce((s, h) => s + Math.abs(signedArea(h)), 0), 0);

/** Material intervals [[zIn, zOut], ...] along a vertical ray at (px, py). */
function materialAlong(mesh, px, py) {
    const v = (i) => [mesh.positions[3 * i], mesh.positions[3 * i + 1], mesh.positions[3 * i + 2]];
    const hits = [];
    for (let t = 0; t < mesh.indices.length; t += 3) {
        const [a, b, c] = [v(mesh.indices[t]), v(mesh.indices[t + 1]), v(mesh.indices[t + 2])];
        const det = (b[0] - a[0]) * (c[1] - a[1]) - (b[1] - a[1]) * (c[0] - a[0]);
        if (Math.abs(det) < 1e-12) continue; // vertical wall
        const u = ((px - a[0]) * (c[1] - a[1]) - (py - a[1]) * (c[0] - a[0])) / det;
        const w = ((b[0] - a[0]) * (py - a[1]) - (b[1] - a[1]) * (px - a[0])) / det;
        if (u < 0 || w < 0 || u + w > 1) continue;
        hits.push({ z: a[2] + u * (b[2] - a[2]) + w * (c[2] - a[2]), up: det > 0 });
    }
    hits.sort((p, q) => p.z - q.z);

    const intervals = [];
    for (let i = 0; i < hits.length; i += 2) {
        assert.equal(hits[i].up, false, 'enters through a downward face');
        assert.equal(hits[i + 1]?.up, true, 'leaves through an upward face');
        intervals.push([hits[i].z, hits[i + 1].z]);
    }

    return intervals;
}

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
        checkHollow(parts[parts.length - 1], { outline, cavity, stem, keycap });
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
    checkHollow(parts[parts.length - 1], { outline, cavity, stem, keycap });
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
        const cap = parts[parts.length - 1];
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
    // A 3 mm radius dot fitted into 30 mm: the outline is a ~5 mm disc, the eroded core sits inside the boss.
    const dot = makeImage(300, 300, (x, y) => (Math.hypot(x + 0.5 - 150, y + 0.5 - 150) < 30 ? [29, 78, 216] : null));
    const settings = { ...SETTINGS, base_shape: 'trace', hollow_cap: true };
    const analysis = analyzeArtwork(dot, settings, KEYCAP_BODY, 'preview');

    assert.deepEqual(analysis.blockers, []);
    assert.deepEqual(analysis.cap.cavity, []);
    assert.ok(analysis.notes.includes(HOLLOW_NOTE));
    const parts = buildParts(analysis, settings, KEYCAP_BODY);
    const cap = parts[parts.length - 1];
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
