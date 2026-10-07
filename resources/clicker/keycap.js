/**
 * Cherry MX keycap cap: a solid cap with a cross-shaped stem socket open at the bottom (one closed stepped shell),
 * or a hollow cap with a cavity under a top plate and a solid boss around the socket.
 */

import { triangulate } from './mesh.js';
import { crossRing, ensureCcw } from './shapes.js';
import { planarFace, pointInPolygons, wallStrip, weld } from './solids.js';
import { signedArea } from './trace.js';

export const KEYCAP_DEFAULTS = {
    size: 30,
    rim: 2,
    corner_radius: 4,
    height: 8,
    socket_depth: 4.5,
    stem: { span: 4.2, arm: 1.35, wall: 1.2 },
    hollow: { wall: 1.6, plate: 2.0, boss_radius: 3.6 },
};

/** Smallest cavity piece kept, mm². */
const MIN_CAVITY_MM2 = 4;
/** Room kept between the boss disc and the analytic cavity edge, mm. */
const BOSS_MARGIN = 0.5;
const BOSS_SEGMENTS = 48;

/** `body.keycap` merged over the defaults (deep for `stem` and `hollow`). */
export function keycapConfig(body) {
    const given = body?.keycap ?? {};

    return {
        ...KEYCAP_DEFAULTS,
        ...given,
        stem: { ...KEYCAP_DEFAULTS.stem, ...(given.stem ?? {}) },
        hollow: { ...KEYCAP_DEFAULTS.hollow, ...(given.hollow ?? {}) },
    };
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

    return { kind: 'body', name: 'Cap', hex: '#9CA3AF', positions, indices: Uint32Array.from(indices) };
}

/**
 * The outline inset by `distance` for the analytic shapes: a rounded square of `size - 2 distance` with corner
 * radius `corner_radius - distance` (at least 0.5), or a circle of radius `size / 2 - distance`.
 * @returns {{outer: number[][], holes: number[][][]}[]} empty when nothing is left
 */
export function insetOutline(outline, shape, keycap, distance) {
    const size = keycap.size - 2 * distance;
    if (size <= 0) return [];
    if (shape === 'circle') return [{ outer: circleOutline({ size }), holes: [] }];
    if (shape === 'square') {
        return [{ outer: squareOutline({ size, corner_radius: Math.max((keycap.corner_radius ?? 0) - distance, 0.5) }), holes: [] }];
    }
    throw new Error(`No analytic inset for the ${shape} shape.`);
}

/** Distance from a point to a closed ring's edges. */
function distanceToRing([px, py], ring) {
    let best = Infinity;
    for (let i = 0; i < ring.length; i++) {
        const [ax, ay] = ring[i];
        const [bx, by] = ring[(i + 1) % ring.length];
        const dx = bx - ax;
        const dy = by - ay;
        const len = dx * dx + dy * dy;
        const t = len === 0 ? 0 : Math.max(0, Math.min(1, ((px - ax) * dx + (py - ay) * dy) / len));
        best = Math.min(best, Math.hypot(px - ax - t * dx, py - ay - t * dy));
    }

    return best;
}

const polygonArea = ({ outer, holes }) => Math.abs(signedArea(outer)) - holes.reduce((sum, hole) => sum + Math.abs(signedArea(hole)), 0);

/**
 * Cavity polygons of the hollow cap (outer CCW, holes CW; holes are solid islands). Square and Circle: the analytic
 * inset with a 48-segment boss disc around the stem; Trace: `trace.cavity(wall, boss_radius)` from the traced outline.
 * Empty when nothing fits.
 */
export function hollowCavity({ shape, outline, stem, keycap, trace }) {
    const { wall, boss_radius: bossRadius } = keycap.hollow;
    if (shape === 'trace') return trace ? trace.cavity(wall, bossRadius) : [];

    const inset = insetOutline(outline, shape, keycap, wall);
    if (inset.length === 0) return [];
    const [{ outer }] = inset;
    const centre = [stem.x, stem.y];
    if (!pointInPolygons(centre, inset) || distanceToRing(centre, outer) < bossRadius + BOSS_MARGIN) return [];

    const disc = Array.from({ length: BOSS_SEGMENTS }, (_, i) => {
        const angle = (-2 * Math.PI * i) / BOSS_SEGMENTS; // clockwise: a hole
        return [stem.x + bossRadius * Math.cos(angle), stem.y + bossRadius * Math.sin(angle)];
    });
    const piece = { outer: ensureCcw(outer), holes: [disc] };

    return polygonArea(piece) >= MIN_CAVITY_MM2 ? [piece] : [];
}

const reversed = (ring) => [...ring].reverse();

/**
 * Hollow keycap cap as ONE closed shell: outline walls z 0..height and the top face; a cavity from z 0 up to
 * `height - plate` bounded by the `cavity` polygons (their holes are solid islands reaching down to z 0) with its
 * roof facing down; the cross socket z 0..socket_depth cut into whichever bottom face holds the stem (the boss
 * island, or the outline's bottom face when the boss merged with the wall).
 */
export function buildHollowCapBody({ outline, cavity, stem, keycap }) {
    const { height, socket_depth: depth } = keycap;
    const roof = height - keycap.hollow.plate;
    const ring = ensureCcw(outline);
    const cross = crossRing(stem.x, stem.y, keycap.stem.span, keycap.stem.arm);

    // Bottom faces (z 0): the outline and every island, each minus the cavity pieces directly inside it.
    const islands = cavity.flatMap(({ holes }) => holes.map((hole) => ensureCcw(hole)));
    const bottom = [{ outer: ring, holes: [] }, ...islands.map((island) => ({ outer: island, holes: [] }))];
    for (const { outer } of cavity) {
        let owner = 0;
        islands.forEach((island, i) => {
            if (
                pointInPolygons(outer[0], [{ outer: island, holes: [] }]) &&
                (owner === 0 || Math.abs(signedArea(island)) < Math.abs(signedArea(bottom[owner].outer)))
            ) {
                owner = i + 1;
            }
        });
        bottom[owner].holes.push(reversed(ensureCcw(outer)));
    }
    const holder = bottom.find((polygon) => pointInPolygons([stem.x, stem.y], [polygon]));
    if (!holder) throw new Error('The stem lies inside the cap cavity.');
    holder.holes.push(reversed(cross));

    const meshes = [
        wallStrip(ring, 0, height, 'out'),
        planarFace([{ outer: ring, holes: [] }], height, true),
        planarFace(bottom, 0, false),
        planarFace(cavity, roof, false),
        wallStrip(cross, 0, depth, 'in'),
        planarFace([{ outer: cross, holes: [] }], depth, false),
    ];
    for (const { outer, holes } of cavity) {
        meshes.push(wallStrip(outer, 0, roof, 'in'));
        for (const hole of holes) meshes.push(wallStrip(hole, 0, roof, 'out'));
    }

    return { kind: 'body', name: 'Cap', hex: '#9CA3AF', ...weld(meshes) };
}
