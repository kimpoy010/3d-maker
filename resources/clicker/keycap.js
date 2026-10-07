/**
 * Cherry MX keycap cap: a solid cap with a cross-shaped stem socket open at the bottom (one closed stepped shell),
 * or a hollow cap with a cavity under a top plate and a solid boss around the socket.
 */

import { triangulate } from './mesh.js';
import { crossRing, ensureCcw } from './shapes.js';
import { planarFace, pointInPolygons, verifiedMesh, wallStrip, weld } from './solids.js';
import { signedArea } from './trace.js';

export const KEYCAP_DEFAULTS = {
    size: 30,
    rim: 2,
    corner_radius: 4,
    height: 8,
    socket_depth: 4.5,
    stem: { span: 4.2, arm: 1.35, wall: 1.2 },
    hollow: { wall: 1.6, plate: 2.0, boss_radius: 3.6 },
    // The open-top switch base the cap slides into (mm): see buildBase.
    base: { clearance: 0.3, wall: 2.0, plate: 1.5, under: 6.0, cavity: 16.2, cutout: 14.1, rest_gap: 8.0, spacing: 10, hex: '#6B7280' },
};

/** Cap material kept around the switch cutout, mm: the cutout plus this on every side must fit in the cap outline. */
const CUTOUT_MARGIN = 0.8;

/** The fraction of the cap's height inside the base at rest: `settings.cap_insertion` clamped to 0.5..1, default 0.5. */
export function capInsertion(settings) {
    const value = Number(settings?.cap_insertion ?? 0.5);

    return Number.isFinite(value) ? Math.min(1, Math.max(0.5, value)) : 0.5;
}

/** Smallest cavity piece kept, mm². */
const MIN_CAVITY_MM2 = 4;
/** Room kept between the boss disc and the analytic cavity edge, mm. */
const BOSS_MARGIN = 0.5;
const BOSS_SEGMENTS = 48;

/** `body.keycap` merged over the defaults (deep for `stem`, `hollow` and `base`). */
export function keycapConfig(body) {
    const given = body?.keycap ?? {};

    return {
        ...KEYCAP_DEFAULTS,
        ...given,
        stem: { ...KEYCAP_DEFAULTS.stem, ...(given.stem ?? {}) },
        hollow: { ...KEYCAP_DEFAULTS.hollow, ...(given.hollow ?? {}) },
        base: { ...KEYCAP_DEFAULTS.base, ...(given.base ?? {}) },
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

/**
 * The outline grown by `distance` for the analytic shapes: a rounded square of `size + 2 distance` with corner radius
 * `corner_radius + distance`, or a circle of radius `size / 2 + distance`; counter-clockwise.
 */
export function growOutline(outline, shape, keycap, distance) {
    const size = keycap.size + 2 * distance;
    if (shape === 'circle') return circleOutline({ size });
    if (shape === 'square') return squareOutline({ size, corner_radius: Math.max(keycap.corner_radius ?? 0, 0) + distance });
    throw new Error(`No analytic growth for the ${shape} shape.`);
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
    const { wall, plate, boss_radius: bossRadius } = keycap.hollow;
    const { span, arm } = keycap.stem;
    // Config guards: the plate must leave a cavity, and the boss must wrap the cross with at least 0.5 mm.
    if (plate >= keycap.height || bossRadius < Math.hypot(span / 2, arm / 2) + 0.5) return [];
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
 * Bottom faces (z 0) of a shell with a cavity open at the bottom: the outline and every island of the cavity, each
 * minus the cavity pieces directly inside it (the innermost containing island owns a piece).
 */
function cavityBottom(ring, cavity) {
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

    return bottom;
}

/** Cavity walls from z 0 to `roof`: facing into the cavity along its outer rings, out of the islands along the holes. */
function cavityWalls(cavity, roof) {
    return cavity.flatMap(({ outer, holes }) => [wallStrip(outer, 0, roof, 'in'), ...holes.map((hole) => wallStrip(hole, 0, roof, 'out'))]);
}

/**
 * Hollow keycap cap as ONE closed shell: outline walls z 0..height and the top face; a cavity from z 0 up to
 * `height - plate` bounded by the `cavity` polygons (their holes are solid islands reaching down to z 0) with its
 * roof facing down; the cross socket z 0..socket_depth cut into whichever bottom face holds the stem (the boss
 * island, or the outline's bottom face when the boss merged with the wall).
 * @returns the part, or null when the welded shell is not a closed manifold with positive volume (or the stem lies
 *   in the cavity); callers then fall back to the solid cap
 */
export function buildHollowCapBody({ outline, cavity, stem, keycap }) {
    const { height, socket_depth: depth } = keycap;
    const roof = height - keycap.hollow.plate;
    const ring = ensureCcw(outline);
    const cross = crossRing(stem.x, stem.y, keycap.stem.span, keycap.stem.arm);

    const bottom = cavityBottom(ring, cavity);
    const holder = bottom.find((polygon) => pointInPolygons([stem.x, stem.y], [polygon]));
    if (!holder) return null; // the stem lies inside the cavity
    holder.holes.push(reversed(cross));

    const meshes = [
        wallStrip(ring, 0, height, 'out'),
        planarFace([{ outer: ring, holes: [] }], height, true),
        planarFace(bottom, 0, false),
        planarFace(cavity, roof, false),
        wallStrip(cross, 0, depth, 'in'),
        planarFace([{ outer: cross, holes: [] }], depth, false),
        ...cavityWalls(cavity, roof),
    ];

    const mesh = verifiedMesh(weld(meshes));

    return mesh && { kind: 'body', name: 'Cap', hex: '#9CA3AF', ...mesh };
}

/** Counter-clockwise axis-aligned square of half size `half` centred on (x, y). */
function squareRing(x, y, half) {
    return [
        [x - half, y - half],
        [x + half, y - half],
        [x + half, y + half],
        [x - half, y + half],
    ];
}

/** True when the segment a-b has a point inside or on the box [x0, x1] x [y0, y1] (Liang-Barsky clipping). */
function segmentTouchesBox([ax, ay], [bx, by], x0, y0, x1, y1) {
    let t0 = 0;
    let t1 = 1;
    const dx = bx - ax;
    const dy = by - ay;
    for (const [p, q] of [
        [-dx, ax - x0],
        [dx, x1 - ax],
        [-dy, ay - y0],
        [dy, y1 - ay],
    ]) {
        if (p === 0) {
            if (q < 0) return false;
            continue;
        }
        const t = q / p;
        if (p < 0) t0 = Math.max(t0, t);
        else t1 = Math.min(t1, t);
        if (t0 > t1) return false;
    }

    return true;
}

/**
 * True when the axis-aligned square of half size `half` centred on the stem lies inside the polygons: its corners and
 * edge midpoints are inside, and no ring (outer edge, hole or island) reaches into or touches it.
 */
function squareInside(polygons, stem, half) {
    const [x0, y0, x1, y1] = [stem.x - half, stem.y - half, stem.x + half, stem.y + half];
    const samples = [...squareRing(stem.x, stem.y, half), [stem.x, y0], [x1, stem.y], [stem.x, y1], [x0, stem.y]];
    if (!samples.every((point) => pointInPolygons(point, polygons))) return false;

    return polygons.every(({ outer, holes }) =>
        [outer, ...holes].every((ring) => ring.every((point, i) => !segmentTouchesBox(point, ring[(i + 1) % ring.length], x0, y0, x1, y1))),
    );
}

/**
 * The switch fits the shape: the plate cutout plus 0.8 mm on every side lies inside the `cap` outline polygons, and
 * the cavity square under the plate lies inside the `base` outline polygons (both centred on the stem).
 */
export function baseFits({ cap, base, stem, keycap }) {
    const { cutout, cavity } = keycap.base;

    return squareInside(cap, stem, cutout / 2 + CUTOUT_MARGIN) && squareInside(base, stem, cavity / 2);
}

/** Height of the base's rim above its bottom: the solid zone, the plate, the rest gap and the inserted part of the cap. */
export function baseTop(keycap, insertion) {
    const { under, plate, rest_gap: restGap } = keycap.base;

    return under + plate + restGap + insertion * keycap.height;
}

/**
 * The open-top switch base the cap slides into, as ONE closed shell, upright with z 0 at its bottom. Zones from the
 * bottom: z 0..under is the `outline` with the square switch cavity open at the bottom; under..under + plate is the
 * plate with the square cutout through it; above it the `pocket` is open up to the rim at `baseTop` and the ring
 * between the pocket and the outline is solid. Faces: outer walls, the rim, the pocket walls, the plate top, the
 * cutout walls, the plate underside around the cutout, the cavity walls and the bottom around the cavity.
 * @param {{pocket: number[][], outline: number[][], stem: {x, y}, keycap: object, insertion: number}} input the pocket
 *   and base outlines (rings in mm), the stem the squares are centred on, the keycap config and the cap insertion
 * @returns the part, or null when the config is unusable, the squares do not lie inside their outlines, or the welded
 *   shell is not a closed manifold with positive volume
 */
export function buildBase({ pocket, outline, stem, keycap, insertion }) {
    const { under, plate, cavity, cutout, rest_gap: restGap, hex } = keycap.base;
    if (!(under > 0 && plate > 0 && restGap >= 0 && cutout > 0 && cavity > cutout)) return null;

    const ring = ensureCcw(outline);
    const pocketRing = ensureCcw(pocket);
    if (!squareInside([{ outer: pocketRing, holes: [] }], stem, cutout / 2) || !squareInside([{ outer: ring, holes: [] }], stem, cavity / 2))
        return null;
    if (!pocketRing.every((point) => pointInPolygons(point, [{ outer: ring, holes: [] }]))) return null;

    const floor = under + plate;
    const top = baseTop(keycap, insertion);
    const hole = squareRing(stem.x, stem.y, cutout / 2);
    const room = squareRing(stem.x, stem.y, cavity / 2);

    const meshes = [
        wallStrip(ring, 0, top, 'out'),
        planarFace([{ outer: ring, holes: [reversed(pocketRing)] }], top, true),
        wallStrip(pocketRing, floor, top, 'in'),
        planarFace([{ outer: pocketRing, holes: [reversed(hole)] }], floor, true),
        wallStrip(hole, under, floor, 'in'),
        planarFace([{ outer: room, holes: [reversed(hole)] }], under, false),
        wallStrip(room, 0, under, 'in'),
        planarFace([{ outer: ring, holes: [reversed(room)] }], 0, false),
    ];

    const mesh = verifiedMesh(weld(meshes));

    return mesh && { kind: 'base', name: 'Base', hex, ...mesh };
}
