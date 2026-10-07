/**
 * Shell building blocks: vertical wall strips along a ring and planar horizontal faces (polygons with holes),
 * welded by exact coordinates into one indexed mesh. Faces use only the ring vertices, so their edges coincide
 * with the strips' edges and a correctly assembled shell is a closed manifold.
 */

import { triangulate } from './mesh.js';
import { signedArea } from './trace.js';

/**
 * Vertical strip along a closed ring (any winding) from z0 to z1. `facing` 'out': normals point away from the
 * area the ring encloses; 'in': into it.
 */
export function wallStrip(ring, z0, z1, facing) {
    const n = ring.length;
    const positions = new Float32Array(6 * n);
    ring.forEach(([x, y], i) => {
        positions.set([x, y, z0], 3 * i);
        positions.set([x, y, z1], 3 * (n + i));
    });

    // Walking a counter-clockwise ring, the quad (a0, b0, b1, a1) faces outward.
    const flip = signedArea(ring) > 0 !== (facing === 'out');
    const indices = new Uint32Array(6 * n);
    for (let i = 0; i < n; i++) {
        const a = i;
        const b = (i + 1) % n;
        const quad = flip ? [b, a, n + a, b, n + a, n + b] : [a, b, n + b, a, n + b, n + a];
        indices.set(quad, 6 * i);
    }

    return { positions, indices };
}

/** Rotations tried, in turn, until earcut gives a clean triangulation (see `cleanTriangulation`). */
const ROTATIONS = [0, 0.3718, 1.1093, 2.0517, 0.7854, 2.6911, 1.5126, 0.1349];

/**
 * True when the triangles exactly tile the polygon: none degenerate, every ring edge used once in the direction that
 * keeps the solid on its left, the Euler count n + 2h - 2, and the summed area equal to the polygon's.
 */
function cleanTriangulation(flat, rings, triangles, eps) {
    const n = flat.length / 2;
    if (triangles.length / 3 !== n + 2 * (rings.length - 1) - 2) return false;

    const edges = new Set();
    let sum = 0;
    let scale = 0;
    for (let t = 0; t < triangles.length; t += 3) {
        const [a, b, c] = [triangles[t], triangles[t + 1], triangles[t + 2]];
        const twice = area2(flat, a, b, c);
        if (!(twice > eps)) return false;
        sum += twice;
        edges
            .add(a * n + b)
            .add(b * n + c)
            .add(c * n + a);
    }

    let expected = 0;
    for (const { start, count, ccw, outer } of rings) {
        expected += (outer ? 1 : -1) * Math.abs(ringArea2(flat, start, count));
        scale += Math.abs(ringArea2(flat, start, count));
        for (let i = 0; i < count; i++) {
            const a = start + i;
            const b = start + ((i + 1) % count);
            // Solid on the left: outer rings counter-clockwise, holes clockwise.
            const forward = ccw === outer;
            if (!edges.has(forward ? a * n + b : b * n + a)) return false;
        }
    }

    return Math.abs(sum - expected) <= 1e-9 * scale;
}

/** Twice the signed area of the triangle (a, b, c) of flat [x, y, ...] points. */
function area2(flat, a, b, c) {
    return (flat[2 * b] - flat[2 * a]) * (flat[2 * c + 1] - flat[2 * a + 1]) - (flat[2 * b + 1] - flat[2 * a + 1]) * (flat[2 * c] - flat[2 * a]);
}

function ringArea2(flat, start, count) {
    let sum = 0;
    for (let i = 0; i < count; i++) {
        const a = start + i;
        const b = start + ((i + 1) % count);
        sum += flat[2 * a] * flat[2 * b + 1] - flat[2 * b] * flat[2 * a + 1];
    }

    return sum;
}

/** Counter-clockwise earcut triangles of one polygon, retried on rotated copies when earcut leaves slivers or gaps. */
function triangulatePolygon(flat, holeStarts) {
    const starts = [0, ...holeStarts];
    const rings = starts.map((start, i) => {
        const count = (starts[i + 1] ?? flat.length / 2) - start;
        return { start, count, outer: i === 0, ccw: ringArea2(flat, start, count) > 0 };
    });

    // Collinear points of mm grids are only collinear up to rounding: areas this small count as zero.
    let extent = 0;
    for (const v of flat) extent = Math.max(extent, Math.abs(v));
    const eps = 1e-9 * Math.max(extent, 1) ** 2;

    let first = null;
    for (const angle of ROTATIONS) {
        const [c, s] = [Math.cos(angle), Math.sin(angle)];
        const turned = angle === 0 ? flat : flat.map((v, i) => (i % 2 === 0 ? c * v - s * flat[i + 1] : s * flat[i - 1] + c * v));
        const triangles = flipSlivers(flat, triangulate(turned, holeStarts), eps); // a rotation keeps the counter-clockwise order
        if (cleanTriangulation(flat, rings, triangles, eps)) return triangles;
        first ??= triangles;
    }

    return first;
}

/**
 * Earcut may emit (near) zero-area triangles where vertices of the bridged rings are collinear. Each such sliver
 * (p, m, r) with m on the segment p-r is merged with the triangle across p-r, (p, r, d), into (p, m, d) and (m, r, d).
 */
function flipSlivers(flat, triangles, eps) {
    const n = flat.length / 2;
    const tris = [];
    for (let t = 0; t < triangles.length; t += 3) tris.push([triangles[t], triangles[t + 1], triangles[t + 2]]);
    const x = (i) => flat[2 * i];
    const y = (i) => flat[2 * i + 1];
    // m lies strictly between p and r on their (shared) line.
    const between = (p, m, r) => (x(m) - x(p)) * (x(r) - x(m)) + (y(m) - y(p)) * (y(r) - y(m)) > 0;
    const sliver = ([a, b, c]) => Math.abs(area2(flat, a, b, c)) <= eps;

    for (let guard = 0; guard < tris.length; guard++) {
        const at = tris.findIndex(sliver);
        if (at < 0) break;

        const owner = new Map();
        tris.forEach((tri, i) => tri.forEach((v, k) => owner.set(v * n + tri[(k + 1) % 3], i)));
        const [a, b, c] = tris[at];
        const [p, m, r] = between(c, a, b) ? [c, a, b] : between(a, b, c) ? [a, b, c] : [b, c, a];
        const across = owner.get(p * n + r);
        if (across === undefined) break;
        const other = tris[across];
        const d = other.find((v) => v !== p && v !== r);
        tris[at] = [p, m, d];
        tris[across] = [m, r, d];
    }

    return tris.flat();
}

/** Horizontal face at height z from polygons [{outer, holes}] (any winding), facing up or down. */
export function planarFace(polygons, z, up) {
    const positions = [];
    const indices = [];
    for (const { outer, holes } of polygons) {
        const base = positions.length / 3;
        const flat = [];
        const holeStarts = [];
        for (const ring of [outer, ...holes]) {
            if (ring !== outer) holeStarts.push(flat.length / 2);
            for (const [x, y] of ring) flat.push(x, y);
        }
        for (let i = 0; i < flat.length; i += 2) positions.push(flat[i], flat[i + 1], z);

        const triangles = triangulatePolygon(flat, holeStarts); // counter-clockwise, i.e. facing up
        for (let t = 0; t < triangles.length; t += 3) {
            const [a, b, c] = [triangles[t], triangles[t + 1], triangles[t + 2]];
            if (up) indices.push(base + a, base + b, base + c);
            else indices.push(base + a, base + c, base + b);
        }
    }

    return { positions: new Float32Array(positions), indices: new Uint32Array(indices) };
}

/** Concatenate meshes, merge vertices with the same coordinates (to `epsilon`) and drop degenerate triangles. */
export function weld(meshes, epsilon = 1e-6) {
    const ids = new Map();
    const positions = [];
    const indices = [];
    const key = (x, y, z) => `${Math.round(x / epsilon)},${Math.round(y / epsilon)},${Math.round(z / epsilon)}`;

    for (const mesh of meshes) {
        const { positions: p, indices: ix } = mesh;
        const remap = new Uint32Array(p.length / 3);
        for (let i = 0; i < remap.length; i++) {
            const k = key(p[3 * i], p[3 * i + 1], p[3 * i + 2]);
            let id = ids.get(k);
            if (id === undefined) {
                id = positions.length / 3;
                ids.set(k, id);
                positions.push(p[3 * i], p[3 * i + 1], p[3 * i + 2]);
            }
            remap[i] = id;
        }
        for (let t = 0; t < ix.length; t += 3) {
            const [a, b, c] = [remap[ix[t]], remap[ix[t + 1]], remap[ix[t + 2]]];
            if (a !== b && b !== c && a !== c) indices.push(a, b, c);
        }
    }

    return { positions: new Float32Array(positions), indices: new Uint32Array(indices) };
}

function inRing([px, py], ring) {
    let inside = false;
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
        const [xi, yi] = ring[i];
        const [xj, yj] = ring[j];
        if (yi > py !== yj > py && px < ((xj - xi) * (py - yi)) / (yj - yi) + xi) inside = !inside;
    }

    return inside;
}

/** True when the point lies inside one of the polygons (inside its outer ring and outside all its holes). */
export function pointInPolygons(point, polygons) {
    return polygons.some(({ outer, holes }) => inRing(point, outer) && !holes.some((hole) => inRing(point, hole)));
}
