import assert from 'node:assert/strict';
import { test } from 'node:test';
import { isClosedManifold, meshVolume } from '../mesh.js';
import { planarFace, pointInPolygons, wallStrip, weld } from '../solids.js';

const near = (actual, expected, tolerance, label) => assert.ok(Math.abs(actual - expected) <= tolerance, `${label}: ${actual} vs ${expected}`);

const square = (half, cx = 0, cy = 0) => [
    [cx - half, cy - half],
    [cx + half, cy - half],
    [cx + half, cy + half],
    [cx - half, cy + half],
];
const cw = (ring) => [...ring].reverse();

/** Sum of the triangles' signed areas projected on xy: positive for faces pointing up. */
function projectedArea({ positions, indices }) {
    let sum = 0;
    for (let t = 0; t < indices.length; t += 3) {
        const [a, b, c] = [indices[t], indices[t + 1], indices[t + 2]];
        sum +=
            ((positions[3 * b] - positions[3 * a]) * (positions[3 * c + 1] - positions[3 * a + 1]) -
                (positions[3 * b + 1] - positions[3 * a + 1]) * (positions[3 * c] - positions[3 * a])) /
            2;
    }

    return sum;
}

test('planarFace of a square with a hole has the net area and faces the requested way, whatever the ring winding', () => {
    for (const [outer, hole] of [
        [square(5), cw(square(2))],
        [cw(square(5)), square(2)],
    ]) {
        const polygons = [{ outer, holes: [hole] }];
        const up = planarFace(polygons, 3, true);
        const down = planarFace(polygons, 3, false);

        near(projectedArea(up), 100 - 16, 1e-9, 'up area');
        near(projectedArea(down), -(100 - 16), 1e-9, 'down area');
        for (let i = 2; i < up.positions.length; i += 3) assert.equal(up.positions[i], 3);
        // Only the ring vertices are used.
        assert.equal(up.positions.length, 3 * 8);
    }
});

test('a prism from two faces and wall strips is a closed manifold with volume area x height', () => {
    for (const [outer, hole] of [
        [square(5), cw(square(2))],
        [cw(square(5)), square(2)],
    ]) {
        const polygons = [{ outer, holes: [hole] }];
        const solid = weld([
            planarFace(polygons, 0, false),
            planarFace(polygons, 4, true),
            wallStrip(outer, 0, 4, 'out'),
            wallStrip(hole, 0, 4, 'in'),
        ]);

        assert.ok(isClosedManifold(solid), 'manifold');
        near(meshVolume(solid), (100 - 16) * 4, 1e-6, 'volume');
        assert.equal(solid.positions.length / 3, 16, 'shared ring vertices are welded');

        // Every normal turned around: still closed, negative volume.
        const inverted = weld([
            planarFace(polygons, 0, true),
            planarFace(polygons, 4, false),
            wallStrip(outer, 0, 4, 'in'),
            wallStrip(hole, 0, 4, 'out'),
        ]);
        assert.ok(isClosedManifold(inverted), 'inverted manifold');
        near(meshVolume(inverted), -(100 - 16) * 4, 1e-6, 'inverted volume');
    }
});

test('wallStrip normals point away from the enclosed area for out and into it for in', () => {
    // A strip around a square: the outward-facing strip's normal at the +x side points +x.
    const normalX = (mesh) => {
        const { positions: p, indices: ix } = mesh;
        for (let t = 0; t < ix.length; t += 3) {
            const [a, b, c] = [ix[t], ix[t + 1], ix[t + 2]].map((i) => [p[3 * i], p[3 * i + 1], p[3 * i + 2]]);
            if (a[0] === 5 && b[0] === 5 && c[0] === 5) {
                const u = [b[0] - a[0], b[1] - a[1], b[2] - a[2]];
                const v = [c[0] - a[0], c[1] - a[1], c[2] - a[2]];

                return Math.sign(u[1] * v[2] - u[2] * v[1]);
            }
        }

        return 0;
    };

    for (const ring of [square(5), cw(square(5))]) {
        assert.equal(normalX(wallStrip(ring, 0, 1, 'out')), 1);
        assert.equal(normalX(wallStrip(ring, 0, 1, 'in')), -1);
    }
});

test('weld merges coincident vertices and drops degenerate triangles', () => {
    const a = { positions: Float32Array.of(0, 0, 0, 1, 0, 0, 0, 1, 0), indices: Uint32Array.of(0, 1, 2) };
    const b = { positions: Float32Array.of(1, 0, 0, 1, 1, 0, 0, 1, 0, 0, 1, 0), indices: Uint32Array.of(0, 1, 2, 1, 2, 3) };
    const welded = weld([a, b]);

    assert.ok(welded.positions instanceof Float32Array);
    assert.ok(welded.indices instanceof Uint32Array);
    assert.equal(welded.positions.length / 3, 4);
    assert.equal(welded.indices.length, 6, 'the triangle with two equal corners is gone');
    assert.deepEqual([...welded.indices], [0, 1, 2, 1, 3, 2]);
});

test('pointInPolygons respects holes and several polygons', () => {
    const polygons = [
        { outer: square(5), holes: [cw(square(2))] },
        { outer: square(1, 20, 0), holes: [] },
    ];

    assert.equal(pointInPolygons([3.5, 0], polygons), true);
    assert.equal(pointInPolygons([0, 0], polygons), false);
    assert.equal(pointInPolygons([20.2, 0.3], polygons), true);
    assert.equal(pointInPolygons([10, 0], polygons), false);
});
