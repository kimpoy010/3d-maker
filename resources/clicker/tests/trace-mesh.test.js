import assert from 'node:assert/strict';
import { test } from 'node:test';
import { bounds, extrudePolygons, isClosedManifold, meshVolume, toMm } from '../mesh.js';
import { signedArea, traceLabel } from '../trace.js';
import { gridFromRows } from './helpers.js';

const trace = (rows, label = 0) => {
    const { labels, width, height } = gridFromRows(rows);

    return traceLabel(labels, width, height, label);
};

test('a filled square traces to one four-point outline', () => {
    const polygons = trace(['......', '.0000.', '.0000.', '.0000.', '.0000.', '......']);

    assert.equal(polygons.length, 1);
    assert.equal(polygons[0].outer.length, 4);
    assert.equal(signedArea(polygons[0].outer), 16);
    assert.equal(polygons[0].holes.length, 0);
});

test('a ring traces to an outline with one hole', () => {
    const polygons = trace(['00000', '0...0', '0...0', '0...0', '00000']);

    assert.equal(polygons.length, 1);
    assert.equal(signedArea(polygons[0].outer), 25);
    assert.equal(polygons[0].holes.length, 1);
    assert.equal(signedArea(polygons[0].holes[0]), -9);
});

test('pixels that only touch at a corner become separate shapes', () => {
    const polygons = trace(['0..', '.0.', '...']);

    assert.equal(polygons.length, 2);
    assert.deepEqual(
        polygons.map((p) => signedArea(p.outer)),
        [1, 1],
    );
});

test('an island inside a hole is its own shape', () => {
    const polygons = trace(['0000000', '0.....0', '0.000.0', '0.000.0', '0.....0', '0000000']);

    assert.equal(polygons.length, 2);
    const ring = polygons.find((p) => p.holes.length === 1);
    assert.equal(signedArea(ring.outer), 42);
    assert.equal(signedArea(ring.holes[0]), -20);
    assert.equal(signedArea(polygons.find((p) => p.holes.length === 0).outer), 6);
});

test('only the requested label is traced', () => {
    const polygons = trace(['0011', '0011'], 1);

    assert.equal(polygons.length, 1);
    assert.equal(signedArea(polygons[0].outer), 4);
});

test('extruding a square gives a closed solid with the right volume and bounds', () => {
    const square = {
        outer: [
            [0, 0],
            [4, 0],
            [4, 4],
            [0, 4],
        ],
        holes: [],
    };
    const mesh = extrudePolygons([square], 6, 8);

    assert.ok(isClosedManifold(mesh));
    assert.ok(Math.abs(meshVolume(mesh) - 32) < 1e-6);
    assert.deepEqual(bounds(mesh), [0, 0, 6, 4, 4, 8]);
});

test('extruding a ring leaves the hole empty', () => {
    const ring = {
        outer: [
            [0, 0],
            [6, 0],
            [6, 6],
            [0, 6],
        ],
        holes: [
            [
                [2, 2],
                [2, 4],
                [4, 4],
                [4, 2],
            ],
        ],
    };
    const mesh = extrudePolygons([ring], 0, 1);

    assert.ok(isClosedManifold(mesh));
    assert.ok(Math.abs(meshVolume(mesh) - 32) < 1e-6); // 36 - 4
});

test('traced pixels convert to millimetres and extrude to the matching volume', () => {
    const { labels, width, height } = gridFromRows(['0000.', '0.00.', '0000.', '.....']);
    const frame = { left: -2, top: 3, mmPerPx: 0.5 };
    const polygons = toMm(traceLabel(labels, width, height, 0), frame);
    const mesh = extrudePolygons(polygons, 1, 3);

    assert.ok(isClosedManifold(mesh));
    // 11 filled pixels x 0.25 mm^2 x 2 mm
    assert.ok(Math.abs(meshVolume(mesh) - 5.5) < 1e-6);
    const [minX, minY, minZ, maxX, maxY, maxZ] = bounds(mesh);
    assert.deepEqual([minX, maxX, minY, maxY, minZ, maxZ], [-2, 0, 1.5, 3, 1, 3]);
});

test('an L shaped region extrudes to a closed solid', () => {
    const { labels, width, height } = gridFromRows(['0...', '0...', '0000']);
    const polygons = toMm(traceLabel(labels, width, height, 0), { left: 0, top: 3, mmPerPx: 1 });
    const mesh = extrudePolygons(polygons, 0, 1);

    assert.ok(isClosedManifold(mesh));
    assert.ok(Math.abs(meshVolume(mesh) - 6) < 1e-6); // 6 filled pixels x 1 mm^2 x 1 mm
});
