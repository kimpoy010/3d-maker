/** Cherry MX keycap body: a shell with a cross-shaped stem socket, built from two extruded shells. */

import { extrudePolygons } from './mesh.js';
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
 * Keycap body from a counter-clockwise outline (mm) and the stem centre. Shell A is the socket floor with the
 * cross hole through it (a little taller than the socket so it overlaps shell B); shell B is the solid cap above.
 */
export function buildKeycapBody({ outline, stem, keycap }) {
    const { span, arm } = keycap.stem;
    const ring = ensureCcw(outline);
    const hole = [...crossRing(stem.x, stem.y, span, arm)].reverse(); // holes must be clockwise

    const shellA = extrudePolygons([{ outer: ring, holes: [hole] }], 0, keycap.socket_depth + 0.4);
    const shellB = extrudePolygons([{ outer: ring, holes: [] }], keycap.socket_depth, keycap.height);

    return { kind: 'body', name: 'Body', hex: '#9CA3AF', ...mergeMeshes([shellA, shellB]), shells: [shellA, shellB] };
}
