import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    chessboardDistance,
    closeGaps,
    components,
    convexHull,
    crossRing,
    dilate,
    distanceToZero,
    ensureCcw,
    erode,
    fillHoles,
    largestSquareCentre,
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

test('chessboardDistance: steps to the nearest clear pixel counting diagonals as one, the outside as clear', () => {
    const full = chessboardDistance(new Uint8Array(25).fill(1), 5, 5);
    assert.deepEqual([...full], [1, 1, 1, 1, 1, 1, 2, 2, 2, 1, 1, 2, 3, 2, 1, 1, 2, 2, 2, 1, 1, 1, 1, 1, 1]);

    // One clear pixel at (1, 1) of a 7x7 block inside a 9x9 grid.
    const mask = rect(9, 9, 1, 1, 7, 7);
    mask[2 * 9 + 2] = 0;
    const d = chessboardDistance(mask, 9, 9);
    assert.equal(d[0], 0);
    assert.equal(d[2 * 9 + 2], 0);
    assert.equal(d[3 * 9 + 3], 1, 'diagonal neighbour of the clear pixel');
    assert.equal(d[4 * 9 + 5], 3, '(5, 4) is 3 steps from the clear pixel and from the outside');
    assert.equal(d[5 * 9 + 5], 3);
    assert.equal(d[4 * 9 + 4], 2);

    // Agrees with a brute-force minimum over all clear pixels and the outside.
    const random = new Uint8Array(13 * 11).map((_, i) => ((i * 7919) % 11 < 9 ? 1 : 0));
    const fast = chessboardDistance(random, 13, 11);
    for (let y = 0; y < 11; y++) {
        for (let x = 0; x < 13; x++) {
            let best = Math.min(x + 1, y + 1, 13 - x, 11 - y);
            for (let v = 0; v < 11; v++)
                for (let u = 0; u < 13; u++) if (!random[v * 13 + u]) best = Math.min(best, Math.max(Math.abs(u - x), Math.abs(v - y)));
            assert.equal(fast[y * 13 + x], random[y * 13 + x] ? best : 0, `(${x}, ${y})`);
        }
    }
});

test('largestSquareCentre: the centre of a square; the crossing of a plus; ties go to the centre of mass', () => {
    assert.deepEqual(largestSquareCentre(rect(15, 15, 3, 3, 11, 11), 15, 15), { x: 7.5, y: 7.5, d: 5 });

    // A plus: every cell on a bar's centre line ties, the centre of mass picks the crossing.
    const plus = rect(15, 15, 0, 6, 14, 8);
    rect(15, 15, 6, 0, 8, 14, plus);
    assert.deepEqual(largestSquareCentre(plus, 15, 15), { x: 7.5, y: 7.5, d: 2 });

    // A wide rectangle: the middle of its centre row.
    assert.deepEqual(largestSquareCentre(rect(21, 9, 1, 1, 19, 7), 21, 9), { x: 10.5, y: 4.5, d: 4 });

    // An L: a 9 wide vertical arm and a 3 high foot reaching far right. The square sits in the thick arm.
    const l = rect(40, 20, 1, 1, 9, 18);
    rect(40, 20, 1, 16, 38, 18, l);
    const centre = largestSquareCentre(l, 40, 20);
    assert.equal(centre.d, 5);
    assert.ok(centre.x >= 1 && centre.x <= 10 && centre.y >= 5 && centre.y <= 14, JSON.stringify(centre));

    // A 120 square (d 60 at its centre) with a long thin tail to the right pulling the centre of mass right: the
    // 2 percent window lets a d 59 cell win; `minimum` raises the window (never above the maximum).
    const tailed = rect(400, 122, 1, 1, 120, 120);
    rect(400, 122, 121, 60, 398, 62, tailed);
    const loose = largestSquareCentre(tailed, 400, 122);
    assert.equal(loose.d, 59);
    assert.ok(loose.x > 61, JSON.stringify(loose));
    assert.equal(largestSquareCentre(tailed, 400, 122, 60).d, 60);
    assert.equal(largestSquareCentre(tailed, 400, 122, 99).d, 60, 'a minimum above the maximum keeps the maximum');
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
