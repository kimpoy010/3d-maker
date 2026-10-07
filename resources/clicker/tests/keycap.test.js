import assert from 'node:assert/strict';
import { test } from 'node:test';
import { KEYCAP_DEFAULTS, buildKeycapBody, circleOutline, keycapConfig, mergeMeshes, squareOutline, stemClearance } from '../keycap.js';
import { bounds, isClosedManifold, meshVolume } from '../mesh.js';
import { signedArea } from '../trace.js';

const near = (actual, expected, relative, label) =>
    assert.ok(Math.abs(actual - expected) <= Math.abs(expected) * relative, `${label}: ${actual} vs ${expected}`);

const lShape = [
    [-15, -15],
    [15, -15],
    [15, -5],
    [-5, -5],
    [-5, 15],
    [-15, 15],
];

const cases = {
    square: { outline: () => squareOutline(KEYCAP_DEFAULTS), stem: { x: 0, y: 0 } },
    circle: { outline: () => circleOutline(KEYCAP_DEFAULTS), stem: { x: 0, y: 0 } },
    L: { outline: () => lShape, stem: { x: -10, y: 0 } },
};

for (const [name, { outline: make, stem }] of Object.entries(cases)) {
    test(`keycap body (${name}) is one closed manifold stepped shell`, () => {
        const outline = make();
        const keycap = keycapConfig({});
        const body = buildKeycapBody({ outline, stem, keycap });
        const { span, arm } = keycap.stem;
        const area = Math.abs(signedArea(outline));

        assert.equal(body.kind, 'body');
        assert.equal(body.name, 'Body');
        assert.equal(body.hex, '#9CA3AF');
        assert.equal(body.shells, undefined);
        assert.ok(isClosedManifold(body), 'manifold');

        const crossArea = 2 * span * arm - arm * arm;
        near(meshVolume(body), area * keycap.height - crossArea * keycap.socket_depth, 0.005, 'volume');
    });

    test(`keycap body (${name}) is solid only above the socket ceiling at the stem centre`, () => {
        const keycap = keycapConfig({});
        const body = buildKeycapBody({ outline: make(), stem, keycap });
        const [px, py] = [stem.x + 0.011, stem.y + 0.007];
        const hits = [];
        const v = (i) => [body.positions[3 * i], body.positions[3 * i + 1], body.positions[3 * i + 2]];

        for (let t = 0; t < body.indices.length; t += 3) {
            const [a, b, c] = [v(body.indices[t]), v(body.indices[t + 1]), v(body.indices[t + 2])];
            const det = (b[0] - a[0]) * (c[1] - a[1]) - (b[1] - a[1]) * (c[0] - a[0]);
            if (Math.abs(det) < 1e-12) continue; // vertical wall
            const u = ((px - a[0]) * (c[1] - a[1]) - (py - a[1]) * (c[0] - a[0])) / det;
            const w = ((b[0] - a[0]) * (py - a[1]) - (b[1] - a[1]) * (px - a[0])) / det;
            if (u < 0 || w < 0 || u + w > 1) continue;
            hits.push({ z: a[2] + u * (b[2] - a[2]) + w * (c[2] - a[2]), up: det > 0 });
        }
        hits.sort((p, q) => p.z - q.z);

        assert.equal(hits.length, 2, 'a vertical ray crosses the surface twice');
        assert.ok(Math.abs(hits[0].z - keycap.socket_depth) < 1e-4 && !hits[0].up, 'enters at the socket ceiling');
        assert.ok(Math.abs(hits[1].z - keycap.height) < 1e-4 && hits[1].up, 'leaves at the top');
    });

    test(`keycap body (${name}) spans the outline and the full height`, () => {
        const outline = make();
        const keycap = keycapConfig({});
        const body = buildKeycapBody({ outline, stem, keycap });
        const [minX, minY, minZ, maxX, maxY, maxZ] = bounds(body);

        assert.equal(minZ, 0);
        assert.equal(maxZ, keycap.height);
        assert.ok(Math.abs(minX - Math.min(...outline.map((p) => p[0]))) < 1e-4);
        assert.ok(Math.abs(maxX - Math.max(...outline.map((p) => p[0]))) < 1e-4);
        assert.ok(Math.abs(minY - Math.min(...outline.map((p) => p[1]))) < 1e-4);
        assert.ok(Math.abs(maxY - Math.max(...outline.map((p) => p[1]))) < 1e-4);
    });

    test(`keycap body (${name}) shows the cross socket at the bottom and at the ceiling`, () => {
        const keycap = keycapConfig({});
        const body = buildKeycapBody({ outline: make(), stem, keycap });
        const { span, arm } = keycap.stem;
        const has = (x, y, z) => {
            for (let i = 0; i < body.positions.length; i += 3) {
                if (Math.abs(body.positions[i] - x) < 1e-4 && Math.abs(body.positions[i + 1] - y) < 1e-4 && Math.abs(body.positions[i + 2] - z) < 1e-4) {
                    return true;
                }
            }

            return false;
        };

        for (const z of [0, keycap.socket_depth]) {
            assert.ok(has(stem.x + span / 2, stem.y - arm / 2, z));
            assert.ok(has(stem.x - span / 2, stem.y + arm / 2, z));
            assert.ok(has(stem.x + arm / 2, stem.y + span / 2, z));
            assert.ok(has(stem.x - arm / 2, stem.y - span / 2, z));
        }
    });
}

test('squareOutline is a counter-clockwise rounded square centred on the origin', () => {
    const { size, corner_radius: r } = KEYCAP_DEFAULTS;
    const ring = squareOutline(KEYCAP_DEFAULTS);
    const area = signedArea(ring);

    assert.ok(area > 0);
    assert.ok(area < size * size);
    assert.ok(area > size * size - (4 - Math.PI) * r ** 2 - 0.5);
    const [minX, minY, , maxX, maxY] = bounds({ positions: Float32Array.from(ring.flatMap((p) => [p[0], p[1], 0])) });
    assert.ok(Math.abs(minX + size / 2) < 1e-6 && Math.abs(maxX - size / 2) < 1e-6);
    assert.ok(Math.abs(minY + size / 2) < 1e-6 && Math.abs(maxY - size / 2) < 1e-6);
});

test('circleOutline is a counter-clockwise circle of the given diameter', () => {
    const ring = circleOutline(KEYCAP_DEFAULTS);
    const area = signedArea(ring);

    assert.ok(area > 0);
    near(area, Math.PI * (KEYCAP_DEFAULTS.size / 2) ** 2, 0.005, 'circle area');
});

test('keycapConfig merges over the defaults, deeply for the stem', () => {
    assert.deepEqual(keycapConfig({}), KEYCAP_DEFAULTS);
    const merged = keycapConfig({ keycap: { stem: { arm: 1.5 }, height: 9 } });

    assert.equal(merged.stem.arm, 1.5);
    assert.equal(merged.stem.span, KEYCAP_DEFAULTS.stem.span);
    assert.equal(merged.stem.wall, KEYCAP_DEFAULTS.stem.wall);
    assert.equal(merged.height, 9);
    assert.equal(KEYCAP_DEFAULTS.stem.arm, 1.35, 'defaults are not mutated');
});

test('stemClearance is the distance from the stem centre to the cross corner plus the wall', () => {
    const { span, arm, wall } = KEYCAP_DEFAULTS.stem;

    near(stemClearance(KEYCAP_DEFAULTS), (span / 2) * Math.hypot(1, arm / span) + wall, 1e-9, 'clearance');
    assert.ok(stemClearance(KEYCAP_DEFAULTS) > span / 2 + wall);
});

test('mergeMeshes concatenates with index offsets', () => {
    const a = { positions: Float32Array.of(0, 0, 0, 1, 0, 0, 0, 1, 0), indices: Uint32Array.of(0, 1, 2) };
    const b = { positions: Float32Array.of(5, 5, 5, 6, 5, 5, 5, 6, 5), indices: Uint32Array.of(0, 2, 1) };
    const merged = mergeMeshes([a, b]);

    assert.equal(merged.positions.length, 18);
    assert.deepEqual([...merged.indices], [0, 1, 2, 3, 5, 4]);
    assert.ok(merged.positions instanceof Float32Array && merged.indices instanceof Uint32Array);
});
