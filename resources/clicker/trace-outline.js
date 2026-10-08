/**
 * Trace base shape: the keycap outline follows the artwork's silhouette, padded by the rim.
 * Worked out on a coarse grid (about 512 cells across the artwork) so the live preview stays fast.
 */

import { CUTOUT_MARGIN, baseFits, stemClearance } from './keycap.js';
import { toMm } from './mesh.js';
import {
    closeGaps,
    components,
    convexHull,
    dilate,
    distanceToZero,
    ensureCcw,
    erode,
    fillHoles,
    largestSquareCentre,
    simplifyRing,
} from './shapes.js';
import { signedArea, traceLabel } from './trace.js';

export const HULL_NOTE = 'The artwork has separate pieces, so the shape joins them with a wrapped outline.';
export const FILLED_NOTE = 'The outline was filled in around your artwork so the cap can hold the switch.';

/** Gap-closing radii in mm, tried in turn until the silhouette is one piece. */
const CLOSING_RADII = [1, 2.5, 5, 8];
/** Wider closings (mm) tried, in turn, when the shape cannot hold the switch; then the convex hull. */
const SOLIDIFY_RADII = [2.5, 5, 8, 12, 16];
const MAX_CELLS = 512;
const MIN_CELL_MM = 0.08;
const SIMPLIFY_MM = 0.03;
/** Inset and cavity pieces smaller than this (mm²) are dropped. */
const MIN_PIECE_MM2 = 4;

/** Cells (0/1) whose centre lies inside the convex hull of the set cells' centres, plus the set cells themselves. */
function hullMask(mask, width, height) {
    const points = [];
    for (let y = 0; y < height; y++) {
        let first = -1;
        let last = -1;
        for (let x = 0; x < width; x++) {
            if (!mask[y * width + x]) continue;
            if (first < 0) first = x;
            last = x;
        }
        if (first >= 0) points.push([first + 0.5, y + 0.5], [last + 0.5, y + 0.5]);
    }

    const hull = convexHull(points);
    const out = Uint8Array.from(mask);
    if (hull.length < 3) return out;

    // A convex polygon cuts each row's centre line in one span: fill it.
    for (let y = 0; y < height; y++) {
        const yc = y + 0.5;
        let lo = Infinity;
        let hi = -Infinity;
        for (let i = 0; i < hull.length; i++) {
            const [ax, ay] = hull[i];
            const [bx, by] = hull[(i + 1) % hull.length];
            if (yc < Math.min(ay, by) || yc > Math.max(ay, by)) continue;
            if (ay === by) {
                lo = Math.min(lo, ax, bx);
                hi = Math.max(hi, ax, bx);
                continue;
            }
            const x = ax + ((yc - ay) * (bx - ax)) / (by - ay);
            lo = Math.min(lo, x);
            hi = Math.max(hi, x);
        }
        if (lo > hi) continue;
        const from = Math.max(0, Math.ceil(lo - 0.5 - 1e-9));
        const to = Math.min(width - 1, Math.floor(hi - 0.5 + 1e-9));
        for (let x = from; x <= to; x++) out[y * width + x] = 1;
    }

    return out;
}

/**
 * @param {{image: {data, width, height}, frame: {left, top, mmPerPx}, keycap: object}} input the scaled RGBA image,
 *   where its top-left corner sits in mm and its mm per pixel, and the keycap config
 * @returns {{outline: number[][], stem: {x: number, y: number}, stemRadius: number, notes: string[],
 *   inset: (distance: number) => {outer, holes}[], cavity: (wall: number, bossRadius: number) => {outer, holes}[],
 *   grow: (distance: number) => number[][]}}
 *   outline in mm (counter-clockwise), the stem at the point farthest from it, and that distance in mm; `inset`
 *   erodes the grown silhouette by `distance` mm, `cavity` also removes the boss disc around the stem (both in mm,
 *   outer rings CCW, holes CW); `grow` dilates it by `distance` mm and returns the outer ring (mm, CCW; holes the
 *   dilation closes off are ignored). The grid pads the artwork by rim + 8 mm, so growth up to 8 mm is never clipped.
 */
export function traceOutline({ image, frame, keycap }) {
    const { data, width, height } = image;
    const artWidth = width * frame.mmPerPx;
    const artHeight = height * frame.mmPerPx;
    const cell = Math.max(MIN_CELL_MM, Math.max(artWidth, artHeight) / MAX_CELLS, frame.mmPerPx);
    const pad = keycap.rim + 8; // room for the widest closing and the rim, so nothing is clipped
    const w = Math.ceil((artWidth + 2 * pad) / cell);
    const h = Math.ceil((artHeight + 2 * pad) / cell);
    const grid = { left: frame.left - pad, top: frame.top + pad, mmPerPx: cell };

    // 1. Silhouette: alpha >= 128 sampled at each cell centre.
    const silhouette = new Uint8Array(w * h);
    const inArt = new Uint8Array(w * h);
    let found = false;
    for (let gy = 0; gy < h; gy++) {
        const py = Math.floor((frame.top - (grid.top - (gy + 0.5) * cell)) / frame.mmPerPx);
        if (py < 0 || py >= height) continue;
        for (let gx = 0; gx < w; gx++) {
            const px = Math.floor((grid.left + (gx + 0.5) * cell - frame.left) / frame.mmPerPx);
            if (px < 0 || px >= width) continue;
            inArt[gy * w + gx] = 1;
            if (data[(py * width + px) * 4 + 3] >= 128) {
                silhouette[gy * w + gx] = 1;
                found = true;
            }
        }
    }
    // Nothing opaque: use the artwork rectangle so there is still a shape to show.
    const mask = found ? silhouette : inArt;

    // 2. Close gaps until it is one piece (a piece inside another's hole counts as joined), else wrap it in its hull.
    let solid = null;
    let closedWith = CLOSING_RADII.length;
    for (const [i, radius] of CLOSING_RADII.entries()) {
        const filled = fillHoles(closeGaps(mask, w, h, radius / cell), w, h);
        if (components(filled, w, h).count <= 1) {
            [solid, closedWith] = [filled, i];
            break;
        }
    }
    const separate = !solid;
    if (separate) solid = hullMask(mask, w, h);

    // 3-4. The cap shape for a solid mask: grown by the rim, its outer ring traced and simplified, and the stem at the
    // centre of the largest axis-aligned square inside it (the switch needs a square). Candidates big enough for the
    // switch cutout (plus a cell for the simplification) are preferred over the 2 percent window, so the tie-break
    // never costs the fit. The outline runs along cell edges, half a cell short of the nearest empty cell's centre.
    const { clearance, wall, cutout } = keycap.base;
    const squareNeed = Math.ceil((cutout / 2 + CUTOUT_MARGIN) / cell + 1.5);
    const shapeOf = (solidMask) => {
        const grown = dilate(solidMask, w, h, keycap.rim / cell);
        const rings = traceLabel(grown, w, h, 1).map((p) => p.outer);
        const outer = rings.reduce((best, ring) => (Math.abs(signedArea(ring)) > Math.abs(signedArea(best)) ? ring : best));
        const [{ outer: ringMm }] = toMm([{ outer, holes: [] }], grid);
        const outline = ensureCcw(simplifyRing(ringMm, SIMPLIFY_MM));
        const centre = largestSquareCentre(grown, w, h, squareNeed);
        const cellIndex = Math.floor(centre.y) * w + Math.floor(centre.x);
        const stem = { x: grid.left + centre.x * cell, y: grid.top - centre.y * cell };
        const stemRadius = Math.max(0, distanceToZero(grown, w, h, true)[cellIndex] - 0.5) * cell;

        return { grown, outline, centre, stem, stemRadius };
    };
    // Growth by `distance` mm: every point within `distance` of a set cell lies in a cell whose centre is within
    // `distance` + one cell diagonal of a set cell's centre, so dilating by that radius keeps the grown ring at least
    // `distance` away (at most about one cell diagonal more); a plain `distance / cell` falls a cell short along
    // diagonal staircases.
    const growRing = (grown, distance) => {
        const pieces = maskPolygons(dilate(grown, w, h, distance / cell + Math.SQRT2), w, h, grid);

        return pieces.reduce((best, piece) => (Math.abs(signedArea(piece.outer)) > Math.abs(signedArea(best.outer)) ? piece : best)).outer;
    };
    // The same test the analysis blocks on: room for the stem, the cutout square in the cap outline and the cavity
    // square in the base outline.
    const holds = (shape) =>
        shape.stemRadius >= stemClearance(keycap) &&
        baseFits({
            cap: [{ outer: shape.outline, holes: [] }],
            base: [{ outer: growRing(shape.grown, clearance + wall), holes: [] }],
            stem: shape.stem,
            keycap,
        });

    // Solidify: when the shape cannot hold the switch, close wider gaps (on a padded copy so wide closings are not
    // clipped by the grid), then wrap the silhouette in its convex hull; the first variant that holds wins. When none
    // does, the plain shape stays and the analysis blocks it.
    const notes = [];
    let shape = shapeOf(solid);
    if (!holds(shape)) {
        const radii = SOLIDIFY_RADII.filter((radius) => separate || radius > CLOSING_RADII[closedWith]);
        const variants = [...radii.map((radius) => () => closeWide(mask, w, h, radius / cell)), () => hullMask(mask, w, h)];
        for (const variant of variants) {
            const candidate = variant();
            if (!candidate) continue;
            const filled = shapeOf(candidate);
            if (holds(filled)) {
                shape = filled;
                notes.push(FILLED_NOTE);
                break;
            }
        }
    }
    if (separate && !notes.includes(FILLED_NOTE)) notes.push(HULL_NOTE);
    const { grown, outline, centre, stem, stemRadius } = shape;

    // 5. Insets and the hollow cap's cavity, from the same grown mask. The boss removal clears every cell whose
    // centre is within the radius plus half a cell diagonal, so the solid boss covers the whole disc.
    const insetMask = (distance) => erode(grown, w, h, distance / cell);
    const inset = (distance) => maskPolygons(insetMask(distance), w, h, grid);
    const cavity = (wall, bossRadius) => {
        const mask = insetMask(wall);
        const reach = bossRadius / cell + Math.SQRT1_2;
        const sx = centre.x - 0.5;
        const sy = centre.y - 0.5;
        for (let y = Math.max(0, Math.floor(sy - reach)); y <= Math.min(h - 1, Math.ceil(sy + reach)); y++) {
            for (let x = Math.max(0, Math.floor(sx - reach)); x <= Math.min(w - 1, Math.ceil(sx + reach)); x++) {
                if ((x - sx) ** 2 + (y - sy) ** 2 <= reach * reach) mask[y * w + x] = 0;
            }
        }

        return maskPolygons(mask, w, h, grid);
    };

    // 6. Growth for the switch base's pocket and outline: the same grown mask dilated, its outer ring.
    const grow = (distance) => growRing(grown, distance);

    return { outline, stem, stemRadius, notes, inset, cavity, grow };
}

/**
 * Close gaps of `radius` cells and fill holes on a copy padded by the radius (so the closing is not clipped by the
 * grid), cropped back; null when the result is not one piece.
 */
function closeWide(mask, width, height, radius) {
    const pad = Math.ceil(radius) + 1;
    const [pw, ph] = [width + 2 * pad, height + 2 * pad];
    const padded = new Uint8Array(pw * ph);
    for (let y = 0; y < height; y++) padded.set(mask.subarray(y * width, (y + 1) * width), (y + pad) * pw + pad);
    const closed = fillHoles(closeGaps(padded, pw, ph, radius), pw, ph);
    const out = new Uint8Array(width * height);
    for (let y = 0; y < height; y++) out.set(closed.subarray((y + pad) * pw + pad, (y + pad) * pw + pad + width), y * width);

    return components(out, width, height).count === 1 ? out : null;
}

/**
 * Clear one cell of every diagonal-only contact (a 2 x 2 block with exactly two set cells on a diagonal), so traced
 * rings never share a corner point. Changes the mask in place; only removes cells.
 */
function separateDiagonals(mask, width, height) {
    let changed = true;
    while (changed) {
        changed = false;
        for (let y = 0; y + 1 < height; y++) {
            for (let x = 0; x + 1 < width; x++) {
                const i = y * width + x;
                const a = mask[i];
                const b = mask[i + 1];
                const c = mask[i + width];
                const d = mask[i + width + 1];
                if (a && d && !b && !c) {
                    mask[i + width + 1] = 0;
                    changed = true;
                } else if (b && c && !a && !d) {
                    mask[i + width] = 0;
                    changed = true;
                }
            }
        }
    }
}

/** The set cells of a mask as mm polygons (outer CCW, holes CW), simplified, without pieces under 4 mm². */
function maskPolygons(mask, width, height, grid) {
    separateDiagonals(mask, width, height);
    const pieces = [];
    for (const { outer, holes } of toMm(traceLabel(mask, width, height, 1), grid)) {
        const ring = ensureCcw(simplifyRing(outer, SIMPLIFY_MM));
        const islands = holes.map((hole) => {
            const simple = simplifyRing(hole, SIMPLIFY_MM);
            return signedArea(simple) > 0 ? simple.reverse() : simple;
        });
        const area = signedArea(ring) + islands.reduce((sum, hole) => sum + signedArea(hole), 0);
        if (ring.length >= 3 && area >= MIN_PIECE_MM2) pieces.push({ outer: ring, holes: islands.filter((hole) => hole.length >= 3) });
    }

    return pieces;
}
