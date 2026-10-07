/** Cherry MX keycap body: a solid cap with a cross-shaped stem socket open at the bottom, as one closed stepped shell. */

import { triangulate } from './mesh.js';
import { crossRing, ensureCcw } from './shapes.js';

export const KEYCAP_DEFAULTS = {
    size: 30,
    rim: 2,
    corner_radius: 4,
    height: 8,
    socket_depth: 4.5,
    stem: { span: 4.2, arm: 1.35, wall: 1.2 },
};

/** `body.keycap` merged over the defaults (deep for `stem`). */
export function keycapConfig(body) {
    const given = body?.keycap ?? {};

    return { ...KEYCAP_DEFAULTS, ...given, stem: { ...KEYCAP_DEFAULTS.stem, ...(given.stem ?? {}) } };
}

/** Minimum distance from the stem centre to the outline: the cross corner reach plus the wall. */
export function stemClearance(keycap) {
    const { span, arm, wall } = keycap.stem;

    return (span / 2) * Math.hypot(1, arm / span) + wall;
}

/** Concatenate meshes, offsetting indices. */
export function mergeMeshes(meshes) {
    const vertexCount = meshes.reduce((n, m) => n + m.positions.length, 0);
    const indexCount = meshes.reduce((n, m) => n + m.indices.length, 0);
    const positions = new Float32Array(vertexCount);
    const indices = new Uint32Array(indexCount);
    let pAt = 0;
    let iAt = 0;

    for (const mesh of meshes) {
        const offset = pAt / 3;
        positions.set(mesh.positions, pAt);
        for (let i = 0; i < mesh.indices.length; i++) indices[iAt + i] = mesh.indices[i] + offset;
        pAt += mesh.positions.length;
        iAt += mesh.indices.length;
    }

    return { positions, indices };
}

/** Counter-clockwise rounded square of `size`, centred on the origin, 8 segments per corner. */
export function squareOutline(keycap, segments = 8) {
    const hw = keycap.size / 2;
    const r = Math.min(Math.max(keycap.corner_radius ?? 0, 0), hw);
    if (r <= 0) {
        return [
            [hw, -hw],
            [hw, hw],
            [-hw, hw],
            [-hw, -hw],
        ];
    }

    const corners = [
        [hw - r, -(hw - r), -90],
        [hw - r, hw - r, 0],
        [-(hw - r), hw - r, 90],
        [-(hw - r), -(hw - r), 180],
    ];
    const ring = [];
    for (const [cx, cy, start] of corners) {
        for (let s = 0; s <= segments; s++) {
            const angle = ((start + (90 * s) / segments) * Math.PI) / 180;
            ring.push([cx + r * Math.cos(angle), cy + r * Math.sin(angle)]);
        }
    }

    return ring;
}

/** Counter-clockwise circle of diameter `size`, centred on the origin, 72 segments. */
export function circleOutline(keycap) {
    const r = keycap.size / 2;

    return Array.from({ length: 72 }, (_, i) => [r * Math.cos((2 * Math.PI * i) / 72), r * Math.sin((2 * Math.PI * i) / 72)]);
}

/**
 * Keycap body from a counter-clockwise outline (mm) and the stem centre: ONE closed, stepped shell. Outer walls run
 * z 0..height, the cross socket walls z 0..socket_depth; the bottom face (z 0) is the outline with the cross as a hole,
 * the top face (z height) is the outline, and the socket ceiling (z socket_depth) is the cross facing down.
 */
export function buildKeycapBody({ outline, stem, keycap }) {
    const { span, arm } = keycap.stem;
    const ring = ensureCcw(outline);
    const hole = [...crossRing(stem.x, stem.y, span, arm)].reverse(); // the hole ring is clockwise
    const n = ring.length;
    const m = hole.length;

    // Vertex blocks: outline at z 0, outline at z height, cross at z 0, cross at z socket_depth.
    const o0 = 0;
    const o1 = n;
    const c0 = 2 * n;
    const c1 = 2 * n + m;
    const positions = new Float32Array(3 * (2 * n + 2 * m));
    const put = (at, points, z) => points.forEach(([x, y], i) => positions.set([x, y, z], 3 * (at + i)));
    put(o0, ring, 0);
    put(o1, ring, keycap.height);
    put(c0, hole, 0);
    put(c1, hole, keycap.socket_depth);

    const indices = [];
    const walls = (lower, upper, count) => {
        for (let i = 0; i < count; i++) {
            const a = i;
            const b = (i + 1) % count;
            indices.push(lower + a, lower + b, upper + b, lower + a, upper + b, upper + a);
        }
    };
    walls(o0, o1, n);
    walls(c0, c1, m);

    // Bottom (z 0, facing down): the outline with the cross as a hole.
    const bottomFlat = [...ring, ...hole].flat();
    const bottom = triangulate(bottomFlat, [n]);
    const onBottom = (i) => (i < n ? o0 + i : c0 + i - n);
    for (let t = 0; t < bottom.length; t += 3) indices.push(onBottom(bottom[t]), onBottom(bottom[t + 2]), onBottom(bottom[t + 1]));

    // Top (z height, facing up).
    const top = triangulate(ring.flat());
    for (let t = 0; t < top.length; t += 3) indices.push(o1 + top[t], o1 + top[t + 1], o1 + top[t + 2]);

    // Socket ceiling (z socket_depth, facing down: the material is above it).
    const ceiling = triangulate(hole.flat());
    for (let t = 0; t < ceiling.length; t += 3) indices.push(c1 + ceiling[t], c1 + ceiling[t + 2], c1 + ceiling[t + 1]);

    return { kind: 'body', name: 'Body', hex: '#9CA3AF', positions, indices: Uint32Array.from(indices) };
}
