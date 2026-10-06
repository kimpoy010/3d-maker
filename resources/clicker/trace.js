/**
 * Trace the boundary of every region of one label into polygons with holes.
 * Pixel coordinates, y pointing down. Regions are 4-connected, so two pixels that only touch at a
 * corner become separate shapes. Collinear points are dropped; nothing else is simplified, so the
 * outlines are exact and cannot self-intersect.
 */

export function signedArea(ring) {
    let sum = 0;
    for (let i = 0; i < ring.length; i++) {
        const [x1, y1] = ring[i];
        const [x2, y2] = ring[(i + 1) % ring.length];
        sum += x1 * y2 - x2 * y1;
    }

    return sum / 2;
}

function pointInRing(px, py, ring) {
    let inside = false;
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
        const [xi, yi] = ring[i];
        const [xj, yj] = ring[j];
        if (yi > py !== yj > py && px < ((xj - xi) * (py - yi)) / (yj - yi) + xi) inside = !inside;
    }

    return inside;
}

function dropCollinear(points) {
    const n = points.length;
    const kept = [];
    for (let i = 0; i < n; i++) {
        const a = points[(i + n - 1) % n];
        const b = points[i];
        const c = points[(i + 1) % n];
        if ((b[0] - a[0]) * (c[1] - b[1]) - (b[1] - a[1]) * (c[0] - b[0]) !== 0) kept.push(b);
    }

    return kept;
}

/**
 * @param {Uint8Array} labels width * height palette indexes
 * @returns {{outer: number[][], holes: number[][][]}[]} outer rings have positive signed area, holes negative
 */
export function traceLabel(labels, width, height, label) {
    const filled = (x, y) => x >= 0 && y >= 0 && x < width && y < height && labels[y * width + x] === label;
    const sx = [];
    const sy = [];
    const ex = [];
    const ey = [];
    const add = (a, b, c, d) => {
        sx.push(a);
        sy.push(b);
        ex.push(c);
        ey.push(d);
    };

    // Clockwise (on screen) edges around every filled pixel wherever the neighbour is not filled.
    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            if (!filled(x, y)) continue;
            if (!filled(x, y - 1)) add(x, y, x + 1, y);
            if (!filled(x + 1, y)) add(x + 1, y, x + 1, y + 1);
            if (!filled(x, y + 1)) add(x + 1, y + 1, x, y + 1);
            if (!filled(x - 1, y)) add(x, y + 1, x, y);
        }
    }

    const stride = width + 1;
    const outgoing = new Map();
    for (let e = 0; e < sx.length; e++) {
        const key = sy[e] * stride + sx[e];
        const list = outgoing.get(key);
        if (list) list.push(e);
        else outgoing.set(key, [e]);
    }

    const turnRank = (cur, next) => {
        const cross = (ex[cur] - sx[cur]) * (ey[next] - sy[next]) - (ey[cur] - sy[cur]) * (ex[next] - sx[next]);

        return cross > 0 ? 0 : cross === 0 ? 1 : 2; // right turn first, then straight, then left
    };

    const used = new Uint8Array(sx.length);
    const loops = [];

    for (let first = 0; first < sx.length; first++) {
        if (used[first]) continue;
        const startKey = sy[first] * stride + sx[first];
        const points = [];
        let cur = first;

        for (;;) {
            used[cur] = 1;
            points.push([sx[cur], sy[cur]]);
            const endKey = ey[cur] * stride + ex[cur];
            const candidates = (outgoing.get(endKey) ?? []).filter((e) => !used[e]);
            if (endKey === startKey) candidates.push(first);
            if (candidates.length === 0) break;
            let next = candidates[0];
            for (const c of candidates) if (turnRank(cur, c) < turnRank(cur, next)) next = c;
            if (next === first) break;
            cur = next;
        }

        const ring = dropCollinear(points);
        if (ring.length >= 3) loops.push(ring);
    }

    const outers = [];
    const holes = [];
    for (const ring of loops) (signedArea(ring) > 0 ? outers : holes).push(ring);

    const polygons = outers.map((outer) => ({ outer, holes: [], area: signedArea(outer) }));

    for (const hole of holes) {
        // A point a quarter pixel beside the hole's first edge lies in the same outer ring as the hole.
        const px = (hole[0][0] + hole[1][0]) / 2 + 0.25;
        const py = (hole[0][1] + hole[1][1]) / 2 + 0.25;
        let owner = null;
        for (const polygon of polygons) {
            if (pointInRing(px, py, polygon.outer) && (owner === null || polygon.area < owner.area)) owner = polygon;
        }
        if (owner) owner.holes.push(hole);
    }

    return polygons.map(({ outer, holes: h }) => ({ outer, holes: h }));
}
