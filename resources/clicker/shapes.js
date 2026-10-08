/** Raster and polygon primitives for the keycap body. Masks are Uint8Arrays of 0/1; distances are in pixels. */

import { signedArea } from './trace.js';

const INF = 1e20;

/** Felzenszwalb 1D squared distance transform of `f` (length n) into `out`, using the scratch arrays. */
function edt1d(f, n, out, v, z) {
    let k = 0;
    v[0] = 0;
    z[0] = -INF;
    z[1] = INF;
    for (let q = 1; q < n; q++) {
        let s = (f[q] + q * q - (f[v[k]] + v[k] * v[k])) / (2 * q - 2 * v[k]);
        while (s <= z[k]) {
            k--;
            s = (f[q] + q * q - (f[v[k]] + v[k] * v[k])) / (2 * q - 2 * v[k]);
        }
        k++;
        v[k] = q;
        z[k] = s;
        z[k + 1] = INF;
    }
    k = 0;
    for (let q = 0; q < n; q++) {
        while (z[k + 1] < q) k++;
        const d = q - v[k];
        out[q] = d * d + f[v[k]];
    }
}

/** Squared distance to the nearest pixel where `isZero(i)` is true. Pixels with none get a huge value. */
function squaredDistance(isZero, width, height) {
    const size = Math.max(width, height);
    const f = new Float64Array(size);
    const out = new Float64Array(size);
    const v = new Int32Array(size);
    const z = new Float64Array(size + 1);
    const grid = new Float64Array(width * height);

    for (let i = 0; i < grid.length; i++) grid[i] = isZero(i) ? 0 : INF;

    for (let x = 0; x < width; x++) {
        for (let y = 0; y < height; y++) f[y] = grid[y * width + x];
        edt1d(f, height, out, v, z);
        for (let y = 0; y < height; y++) grid[y * width + x] = out[y];
    }
    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) f[x] = grid[y * width + x];
        edt1d(f, width, out, v, z);
        for (let x = 0; x < width; x++) grid[y * width + x] = out[x];
    }

    return grid;
}

/** Squared distance from every pixel to the nearest background pixel, optionally counting the outside as background. */
function squaredToZero(mask, width, height, outsideIsZero) {
    if (!outsideIsZero) return squaredDistance((i) => mask[i] === 0, width, height);

    const w = width + 2;
    const h = height + 2;
    const padded = squaredDistance(
        (i) => {
            const x = i % w;
            const y = (i - x) / w;
            if (x === 0 || y === 0 || x === w - 1 || y === h - 1) return true;

            return mask[(y - 1) * width + (x - 1)] === 0;
        },
        w,
        h,
    );
    const out = new Float64Array(width * height);
    for (let y = 0; y < height; y++) for (let x = 0; x < width; x++) out[y * width + x] = padded[(y + 1) * w + x + 1];

    return out;
}

/** Disc dilation: set where a set pixel lies within `radius` (Euclidean, inclusive). */
export function dilate(mask, width, height, radius) {
    if (radius <= 0) return Uint8Array.from(mask);

    const squared = squaredDistance((i) => mask[i] === 1, width, height);
    const out = new Uint8Array(mask.length);
    const limit = radius * radius;
    for (let i = 0; i < out.length; i++) out[i] = squared[i] <= limit ? 1 : 0;

    return out;
}

/** Disc erosion: keep set pixels farther than `radius` from the background, the outside counting as background. */
export function erode(mask, width, height, radius) {
    if (radius <= 0) return Uint8Array.from(mask);

    const squared = squaredToZero(mask, width, height, true);
    const out = new Uint8Array(mask.length);
    const limit = radius * radius;
    for (let i = 0; i < out.length; i++) out[i] = mask[i] === 1 && squared[i] > limit ? 1 : 0;

    return out;
}

/** Dilate then erode. The grid must already be padded by at least `radius` pixels. */
export function closeGaps(mask, width, height, radius) {
    if (radius <= 0) return Uint8Array.from(mask);

    return erode(dilate(mask, width, height, radius), width, height, radius);
}

/** Set every background pixel that is not 4-connected to the image border. */
export function fillHoles(mask, width, height) {
    const outside = new Uint8Array(mask.length);
    const stack = [];
    const push = (p) => {
        if (mask[p] === 0 && !outside[p]) {
            outside[p] = 1;
            stack.push(p);
        }
    };

    for (let x = 0; x < width; x++) {
        push(x);
        push((height - 1) * width + x);
    }
    for (let y = 0; y < height; y++) {
        push(y * width);
        push(y * width + width - 1);
    }
    while (stack.length) {
        const p = stack.pop();
        const x = p % width;
        if (x > 0) push(p - 1);
        if (x < width - 1) push(p + 1);
        if (p >= width) push(p - width);
        if (p < (height - 1) * width) push(p + width);
    }

    const out = new Uint8Array(mask.length);
    for (let i = 0; i < out.length; i++) out[i] = outside[i] ? 0 : 1;

    return out;
}

/** 4-connected components of the set pixels. Labels start at 1; 0 is background. */
export function components(mask, width, height) {
    const labels = new Int32Array(mask.length);
    const stack = [];
    let count = 0;

    for (let start = 0; start < mask.length; start++) {
        if (mask[start] === 0 || labels[start]) continue;
        count++;
        labels[start] = count;
        stack.push(start);
        while (stack.length) {
            const p = stack.pop();
            const x = p % width;
            const visit = (q) => {
                if (mask[q] === 1 && !labels[q]) {
                    labels[q] = count;
                    stack.push(q);
                }
            };
            if (x > 0) visit(p - 1);
            if (x < width - 1) visit(p + 1);
            if (p >= width) visit(p - width);
            if (p < (height - 1) * width) visit(p + width);
        }
    }

    return { count, labels };
}

/**
 * Euclidean distance (pixels) from the centre of pixel (x, y) to the nearest clear pixel centre, the outside of the
 * grid counting as clear: the value a full distance transform with the outside as background gives at that pixel.
 * `d` is a chessboard bound on that distance (the pixel's `chessboardDistance`): a clear pixel lies within `d`
 * chessboard steps, so within `d` x sqrt 2, and only that box is searched.
 */
export function clearDistance(mask, width, height, x, y, d) {
    let best = Math.min(x + 1, y + 1, width - x, height - y) ** 2;
    const reach = Math.ceil(d * Math.SQRT2);
    for (let v = Math.max(0, y - reach); v <= Math.min(height - 1, y + reach); v++) {
        const dy2 = (v - y) ** 2;
        if (dy2 >= best) continue;
        const row = v * width;
        for (let u = Math.max(0, x - reach); u <= Math.min(width - 1, x + reach); u++) {
            if (!mask[row + u]) best = Math.min(best, (u - x) ** 2 + dy2);
        }
    }

    return Math.sqrt(best);
}

/**
 * Chessboard (L-infinity) distance from every pixel to the nearest clear pixel, the outside counting as clear: a set
 * pixel at distance d is the centre of a (2d - 1) x (2d - 1) block of set pixels. Two-pass chamfer; 0 on clear pixels.
 */
export function chessboardDistance(mask, width, height) {
    const d = new Int32Array(width * height);
    const at = (x, y) => (x < 0 || y < 0 || x >= width || y >= height ? 0 : d[y * width + x]);
    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            if (mask[y * width + x]) d[y * width + x] = 1 + Math.min(at(x - 1, y), at(x - 1, y - 1), at(x, y - 1), at(x + 1, y - 1));
        }
    }
    for (let y = height - 1; y >= 0; y--) {
        for (let x = width - 1; x >= 0; x--) {
            const i = y * width + x;
            if (d[i]) d[i] = Math.min(d[i], 1 + Math.min(at(x + 1, y), at(x + 1, y + 1), at(x, y + 1), at(x - 1, y + 1)));
        }
    }

    return d;
}

/**
 * The centre of the largest axis-aligned square of set pixels: among the pixels whose chessboard distance is within
 * 2 percent of the maximum (but at least `minimum`, capped at the maximum), the one nearest the centre of mass of
 * the set pixels. Returns the pixel centre, its chessboard distance `d` and the maximum distance `max`.
 */
export function largestSquareCentre(mask, width, height, minimum = 0) {
    const d = chessboardDistance(mask, width, height);
    let max = 0;
    let [sx, sy, n] = [0, 0, 0];
    for (let i = 0; i < d.length; i++) {
        if (!mask[i]) continue;
        max = Math.max(max, d[i]);
        const x = i % width;
        sx += x;
        sy += (i - x) / width;
        n++;
    }
    const [cx, cy] = n ? [sx / n, sy / n] : [width / 2, height / 2];
    const floor = Math.max(0.98 * max, Math.min(max, minimum));
    let best = { x: 0.5, y: 0.5, d: 0, max };
    let bestDistance = Infinity;
    for (let i = 0; i < d.length; i++) {
        if (d[i] < floor || d[i] === 0) continue;
        const x = i % width;
        const y = (i - x) / width;
        const distance = (x - cx) ** 2 + (y - cy) ** 2;
        if (distance < bestDistance) {
            bestDistance = distance;
            best = { x: x + 0.5, y: y + 0.5, d: d[i], max };
        }
    }

    return best;
}

/** Convex hull, counter-clockwise, without repeating the first point (Andrew's monotone chain). */
export function convexHull(points) {
    const sorted = points
        .map((p) => [p[0], p[1]])
        .sort((a, b) => a[0] - b[0] || a[1] - b[1])
        .filter((p, i, all) => i === 0 || p[0] !== all[i - 1][0] || p[1] !== all[i - 1][1]);
    if (sorted.length < 3) return sorted;

    const cross = (o, a, b) => (a[0] - o[0]) * (b[1] - o[1]) - (a[1] - o[1]) * (b[0] - o[0]);
    const half = (list) => {
        const chain = [];
        for (const p of list) {
            while (chain.length >= 2 && cross(chain[chain.length - 2], chain[chain.length - 1], p) <= 0) chain.pop();
            chain.push(p);
        }
        chain.pop();

        return chain;
    };

    return [...half(sorted), ...half([...sorted].reverse())];
}

function segmentDistance(p, a, b) {
    const dx = b[0] - a[0];
    const dy = b[1] - a[1];
    const len = dx * dx + dy * dy;
    if (len === 0) return Math.hypot(p[0] - a[0], p[1] - a[1]);
    const t = Math.max(0, Math.min(1, ((p[0] - a[0]) * dx + (p[1] - a[1]) * dy) / len));

    return Math.hypot(p[0] - (a[0] + t * dx), p[1] - (a[1] + t * dy));
}

/** Douglas-Peucker on an open path; returns the kept points including both ends. */
function simplifyPath(path, tolerance) {
    if (path.length < 3) return path;

    const keep = new Uint8Array(path.length);
    keep[0] = 1;
    keep[path.length - 1] = 1;
    const stack = [[0, path.length - 1]];
    while (stack.length) {
        const [a, b] = stack.pop();
        let far = -1;
        let farDist = tolerance;
        for (let i = a + 1; i < b; i++) {
            const d = segmentDistance(path[i], path[a], path[b]);
            if (d > farDist) {
                farDist = d;
                far = i;
            }
        }
        if (far >= 0) {
            keep[far] = 1;
            stack.push([a, far], [far, b]);
        }
    }

    return path.filter((_, i) => keep[i]);
}

/** Douglas-Peucker on a closed ring, split at the two points farthest apart. Keeps at least 3 points when it can. */
export function simplifyRing(ring, tolerance) {
    if (ring.length <= 3) return ring.map((p) => [p[0], p[1]]);

    let i0 = 0;
    let j0 = 1;
    let widest = -1;
    for (let i = 0; i < ring.length; i++) {
        for (let j = i + 1; j < ring.length; j++) {
            const d = (ring[i][0] - ring[j][0]) ** 2 + (ring[i][1] - ring[j][1]) ** 2;
            if (d > widest) {
                widest = d;
                i0 = i;
                j0 = j;
            }
        }
    }

    const first = simplifyPath(ring.slice(i0, j0 + 1), tolerance);
    const second = simplifyPath([...ring.slice(j0), ...ring.slice(0, i0 + 1)], tolerance);
    const out = [...first, ...second.slice(1, -1)];

    if (out.length < 3) {
        // Tolerance swallowed everything between the two ends: keep the point farthest from their chord.
        let far = null;
        let farDist = 0;
        for (const p of ring) {
            const d = Math.abs((ring[j0][0] - ring[i0][0]) * (ring[i0][1] - p[1]) - (ring[i0][0] - p[0]) * (ring[j0][1] - ring[i0][1]));
            if (d > farDist) {
                farDist = d;
                far = p;
            }
        }
        if (far) out.splice(1, 0, far);
    }

    return out.map((p) => [p[0], p[1]]);
}

/** 12-point counter-clockwise outline of a plus sign centred on (cx, cy): bars `span` long and `arm` wide. */
export function crossRing(cx, cy, span, arm) {
    const h = span / 2;
    const a = arm / 2;
    const offsets = [
        [h, -a],
        [h, a],
        [a, a],
        [a, h],
        [-a, h],
        [-a, a],
        [-h, a],
        [-h, -a],
        [-a, -a],
        [-a, -h],
        [a, -h],
        [a, -a],
    ];

    return offsets.map(([x, y]) => [cx + x, cy + y]);
}

/** The ring itself when it is counter-clockwise, otherwise a reversed copy. */
export function ensureCcw(ring) {
    return signedArea(ring) < 0 ? [...ring].reverse() : ring;
}
