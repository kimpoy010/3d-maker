import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    closeGaps,
    components,
    convexHull,
    crossRing,
    dilate,
    distanceToZero,
    ensureCcw,
    erode,
    fillHoles,
    poleOfInaccessibility,
    simplifyRing,
} from '../shapes.js';
import { signedArea } from '../trace.js';

/** Build a mask from rows of '#' (set) and '.' (clear). */
function maskOf(rows) {
    const height = rows.length;
    const width = rows[0].length;
    const mask = new Uint8Array(width * height);
    rows.forEach((row, y) => [...row].forEach((c, x) => (mask[y * width + x] = c === '#' ? 1 : 0)));

    return { mask, width, height };
}

/** A mask of the given size with the rectangle x0..x1, y0..y1 (inclusive) set. */
function rect(width, height, x0, y0, x1, y1, into = new Uint8Array(width * height)) {
    for (let y = y0; y <= y1; y++) for (let x = x0; x <= x1; x++) into[y * width + x] = 1;

    return into;
}

const count = (mask) => mask.reduce((a, b) => a + b, 0);

test('distanceToZero: diagonal neighbour is sqrt(2) and a far pixel is the exact Euclidean distance', () => {
    const mask = new Uint8Array(25).fill(1);
    mask[0] = 0;
    const d = distanceToZero(mask, 5, 5);

    assert.equal(d[0], 0);
    assert.ok(Math.abs(d[1 * 5 + 1] - Math.SQRT2) < 1e-6);
    assert.ok(Math.abs(d[3 * 5 + 4] - 5) < 1e-6, 'pixel (4,3) is a 3-4-5 triangle from (0,0)');
    assert.ok(Math.abs(d[4 * 5 + 4] - Math.hypot(4, 4)) < 1e-5);
});

test('distanceToZero: the image border is background only when outsideIsZero is passed', () => {
    const mask = new Uint8Array(9).fill(1);
    const inside = distanceToZero(mask, 3, 3, true);

    assert.equal(inside[0], 1, 'a corner pixel is one step from the outside');
    assert.equal(inside[4], 2, 'the centre is two steps from the outside');
    assert.ok(distanceToZero(mask, 3, 3)[4] > 1e6, 'with no background anywhere the distance is effectively infinite');
});

test('dilate grows a single pixel into the 13-pixel disc of radius 2, and radius 0 copies', () => {
    const mask = new Uint8Array(81);
    mask[4 * 9 + 4] = 1;
    const out = dilate(mask, 9, 9, 2);

    // Lattice points with x^2 + y^2 <= 4: (0,0), 4 at distance 1, 4 at sqrt2, 4 at distance 2.
    assert.equal(count(out), 13);
    assert.equal(out[4 * 9 + 6], 1);
    assert.equal(out[4 * 9 + 7], 0);
    assert.equal(out[6 * 9 + 6], 0, 'the corner (2,2) is at distance sqrt(8) > 2');
    assert.deepEqual(dilate(mask, 9, 9, 0), mask);
    assert.notEqual(dilate(mask, 9, 9, 0), mask, 'a copy, not the same array');
});

test('erode of a 7x7 block by radius 2 leaves the central 3x3', () => {
    const mask = rect(11, 11, 2, 2, 8, 8);
    const out = erode(mask, 11, 11, 2);

    // Block-local index i in 0..6: nearest background is min(i + 1, 7 - i) away along an axis (diagonals are
    // farther), which must exceed 2, so i is 2..4 in both axes.
    assert.equal(count(out), 9);
    assert.deepEqual(out, rect(11, 11, 4, 4, 6, 6));
});

test('erode treats the outside of the image as background', () => {
    const mask = new Uint8Array(25).fill(1);

    assert.equal(count(erode(mask, 5, 5, 1)), 9, 'a one-pixel rim is stripped');
});

test('closeGaps joins two blocks 2 pixels apart with radius 2 and not with radius 0', () => {
    const mask = rect(14, 9, 3, 3, 5, 5);
    rect(14, 9, 8, 3, 10, 5, mask);
    assert.equal(components(mask, 14, 9).count, 2);

    const closed = closeGaps(mask, 14, 9, 2);
    assert.equal(components(closed, 14, 9).count, 1);
    assert.equal(closed[4 * 14 + 6], 1);
    assert.equal(closed[4 * 14 + 7], 1);
    for (let i = 0; i < mask.length; i++) if (mask[i]) assert.equal(closed[i], 1, 'closing never removes pixels');

    assert.equal(components(closeGaps(mask, 14, 9, 0), 14, 9).count, 2);
});

test('fillHoles fills a ring and leaves an open C unchanged', () => {
    const ring = maskOf(['.......', '.#####.', '.#...#.', '.#...#.', '.#...#.', '.#####.', '.......']);
    const filled = fillHoles(ring.mask, 7, 7);
    assert.deepEqual(filled, rect(7, 7, 1, 1, 5, 5));

    const c = maskOf(['.......', '.#####.', '.#.....', '.#.....', '.#.....', '.#####.', '.......']);
    assert.deepEqual(fillHoles(c.mask, 7, 7), c.mask);
});

test('components counts separate blocks and treats diagonal contact as separate', () => {
    const two = rect(10, 4, 0, 0, 2, 2);
    rect(10, 4, 6, 0, 8, 2, two);
    const result = components(two, 10, 4);
    assert.equal(result.count, 2);
    assert.equal(result.labels[0], 1);
    assert.equal(result.labels[6], 2);
    assert.equal(result.labels[3], 0);

    const diagonal = maskOf(['#.', '.#']);
    assert.equal(components(diagonal.mask, 2, 2).count, 2);
    const edge = maskOf(['##', '.#']);
    assert.equal(components(edge.mask, 2, 2).count, 1);
});

test('poleOfInaccessibility of a square is its centre, of a thick plus its crossing', () => {
    const square = rect(15, 15, 3, 3, 11, 11);
    const pole = poleOfInaccessibility(square, 15, 15);
    assert.deepEqual(pole, { x: 7.5, y: 7.5, r: 5 });

    // Bars 3 wide (indexes 6..8) across a 15x15 grid. The centre pixel (7,7) is sqrt(2^2 + 2^2) from the
    // nearest inner corner background pixel (5,5); every arm pixel is at most 2 from a bar edge.
    const plus = rect(15, 15, 0, 6, 14, 8);
    rect(15, 15, 6, 0, 8, 14, plus);
    const cross = poleOfInaccessibility(plus, 15, 15);
    assert.equal(cross.x, 7.5);
    assert.equal(cross.y, 7.5);
    assert.ok(Math.abs(cross.r - Math.sqrt(8)) < 1e-5);
});

test('convexHull of a square with an interior point is the four corners, counter-clockwise', () => {
    const hull = convexHull([
        [0, 0],
        [2, 2],
        [4, 4],
        [4, 0],
        [0, 4],
        [2, 0],
    ]);

    assert.equal(hull.length, 4, 'the interior point and the collinear edge point are dropped');
    assert.equal(signedArea(hull), 16);
    assert.deepEqual(
        [...hull].sort((a, b) => a[0] - b[0] || a[1] - b[1]),
        [
            [0, 0],
            [0, 4],
            [4, 0],
            [4, 4],
        ],
    );
});

function distanceToSegment([px, py], [ax, ay], [bx, by]) {
    const dx = bx - ax;
    const dy = by - ay;
    const t = Math.max(0, Math.min(1, ((px - ax) * dx + (py - ay) * dy) / (dx * dx + dy * dy)));

    return Math.hypot(px - (ax + t * dx), py - (ay + t * dy));
}

test('simplifyRing keeps a circle within tolerance with fewer points, and reduces a padded square to 4', () => {
    const circle = Array.from({ length: 200 }, (_, i) => [10 * Math.cos((2 * Math.PI * i) / 200), 10 * Math.sin((2 * Math.PI * i) / 200)]);
    const simple = simplifyRing(circle, 0.05);

    assert.ok(simple.length < 200 && simple.length >= 3);
    for (const p of circle) {
        const nearest = Math.min(...simple.map((a, i) => distanceToSegment(p, a, simple[(i + 1) % simple.length])));
        assert.ok(nearest <= 0.05 + 1e-9, `point ${p} is ${nearest} from the simplified ring`);
    }

    const square = [];
    for (let i = 0; i < 4; i++) square.push([i, 0]);
    for (let i = 0; i < 4; i++) square.push([4, i]);
    for (let i = 0; i < 4; i++) square.push([4 - i, 4]);
    for (let i = 0; i < 4; i++) square.push([0, 4 - i]);
    const out = simplifyRing(square, 0.01);
    assert.equal(out.length, 4);
    assert.equal(Math.abs(signedArea(out)), 16);
});

test('simplifyRing never drops below 3 points for a real triangle, even at a huge tolerance', () => {
    const out = simplifyRing(
        [
            [0, 0],
            [5, 0],
            [10, 0],
            [5, 8],
        ],
        100,
    );

    assert.equal(out.length, 3);
});

test('crossRing is a 12-point counter-clockwise plus whose area and bounds follow from span and arm', () => {
    const ring = crossRing(5, -3, 10, 4);

    assert.equal(ring.length, 12);
    assert.equal(signedArea(ring), 2 * 10 * 4 - 4 * 4);
    const xs = ring.map((p) => p[0]);
    const ys = ring.map((p) => p[1]);
    assert.equal(Math.min(...xs), 0);
    assert.equal(Math.max(...xs), 10);
    assert.equal(Math.min(...ys), -8);
    assert.equal(Math.max(...ys), 2);
});

test('ensureCcw reverses a clockwise ring and leaves a counter-clockwise one alone', () => {
    const ccw = [
        [0, 0],
        [4, 0],
        [4, 4],
        [0, 4],
    ];
    const cw = [...ccw].reverse();

    assert.equal(ensureCcw(ccw), ccw);
    assert.deepEqual(ensureCcw(cw), ccw);
    assert.ok(signedArea(ensureCcw(cw)) > 0);
});
