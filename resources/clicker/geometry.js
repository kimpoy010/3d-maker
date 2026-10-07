import { hexToRgb } from './color.js';
import {
    baseFits,
    buildBase,
    buildHollowCapBody,
    buildKeycapBody,
    capInsertion,
    circleOutline,
    growOutline,
    hollowCavity,
    keycapConfig,
    squareOutline,
    stemClearance,
} from './keycap.js';
import { extrudePolygons, toMm } from './mesh.js';
import { BG, applyCapMask, buildLabels, cleanSpecks, downscale, paintOutline, removeBackground, thinResidue } from './raster.js';
import { traceOutline } from './trace-outline.js';
import { traceLabel } from './trace.js';

import { suggestPalette } from './palette.js';

export { suggestPalette };

/** Longest side of the working pixel grid. */
export const MAX_SIDE = { preview: 1024, final: 2048 };

export const BASE_SHAPES = ['square', 'circle', 'trace'];

export const STEM_BLOCKER = 'This shape is too thin to hold the switch stem. Make the artwork larger or choose Square or Circle.';

export const NO_BACKGROUND_NOTE =
    'The background could not be removed, so Trace follows the whole image. Try a plainer background or use Square or Circle.';
export const HOLLOW_NOTE = 'The shape is too small for a hollow cap, so the cap is solid.';
export const HOLLOW_FAILED_NOTE = 'The hollow cap could not be built for this shape, so the cap is solid.';

export const BASE_BLOCKER = 'This shape is too small to hold the switch base. Make the artwork larger or choose Square or Circle.';
export const BASE_FAILED_BLOCKER = 'The switch base could not be built for this shape. Make the artwork larger or choose Square or Circle.';

/** Part layouts: `assembled` (the editor preview) and `print` (the downloads). */
export const LAYOUTS = ['assembled', 'print'];

const clamp = (value, min, max) => Math.max(min, Math.min(max, value));

/** True for a body whose mesh is generated here (a Cherry MX keycap) rather than loaded from files. */
const isGenerated = (body) => body?.generator === 'mx_keycap';

/** The chosen base shape; missing or unknown values mean square. */
const baseShape = (settings) => (BASE_SHAPES.includes(settings.base_shape) ? settings.base_shape : 'square');

/** Cap mask and artwork area for a keycap: Square/Circle keep a rim free; Trace fits the art to the full size and has no mask. */
function keycapLayout(keycap, shape) {
    const { size, rim } = keycap;
    if (shape === 'trace') return { cap: null, artwork_area: { cx: 0, cy: 0, width: size, height: size } };

    return {
        cap: {
            shape: shape === 'circle' ? 'circle' : 'rounded',
            width: size,
            height: size,
            corner_radius: keycap.corner_radius,
            top_z: keycap.height,
            inset: shape === 'circle' ? rim : 0, // the circle mask keeps the rim free; the square one is clipped by the artwork area
        },
        artwork_area: { cx: 0, cy: 0, width: size - 2 * rim, height: size - 2 * rim },
    };
}

/** Keep one pixel of every `factor` x `factor` block: a cheap, small copy for the thin-detail check. */
function subsample(labels, width, height, factor) {
    const w = Math.ceil(width / factor);
    const h = Math.ceil(height / factor);
    const out = new Uint8Array(w * h);
    for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) out[y * w + x] = labels[y * factor * width + x * factor];

    return { labels: out, width: w, height: h };
}

/**
 * The artwork as the pipeline sees it. A plain background is made transparent: by default for a generated
 * keycap body unless `remove_background` is false; for a legacy body only when `remove_background` is true.
 */
export function prepareArtwork(image, settings, body) {
    const on = isGenerated(body) ? settings?.remove_background !== false : settings?.remove_background === true;

    return on ? removeBackground(image) : image;
}

/** How many pixels are transparent (alpha below 128). */
function clearCount(image) {
    let n = 0;
    for (let i = 3; i < image.data.length; i += 4) if (image.data[i] < 128) n++;

    return n;
}

/**
 * Suggest `count` colours from the prepared artwork. When a background was removed, the anti-aliased pixels
 * along the new edge are blends with that background, so they are left out of the suggestion (not out of the
 * label snapping). The editor and the tests share this one path.
 */
export function paletteFromArtwork(image, count, settings, body) {
    const prepared = prepareArtwork(image, settings, body);
    const removed = prepared !== image && clearCount(prepared) > clearCount(image);

    return suggestPalette(prepared, count, { ignoreEdge: removed });
}

/**
 * The slow half of the pipeline: pixels in, millimetre polygons out. Re-run it when the artwork,
 * the palette, the scale, the position or the outline change.
 *
 * @param {{data: Uint8Array|Uint8ClampedArray, width: number, height: number}} image RGBA pixels
 * @param {object} settings {colors: {hex, height_mm, enabled}[], scale, offset_x, offset_y, outline_mm, outline_hex}
 * @param {object} body {cap, artwork_area, max_layer_height, min_feature}, or a generated keycap body with
 *   {generator: 'mx_keycap', keycap, max_layer_height, min_feature}; then settings.base_shape picks the outline
 * @param {'preview'|'final'} quality
 * @returns analysis; for generated bodies `cap` = {outline, stem}, plus `cavity` (hollow cap polygons, possibly
 *   empty) and `hollowBody` (the verified hollow cap part, or null) when settings.hollow_cap is true,
 *   `pocketOutline` and `baseOutline` (the outline grown by the base clearance, and by clearance + wall) and
 *   `baseBody` (the verified open-top switch base, upright in its own frame, or null; only set when nothing blocks;
 *   it depends on settings.cap_insertion); `blockers` lists reasons the cap or base cannot be built
 */
export function analyzeArtwork(source, settings, body, quality = 'preview') {
    const image = prepareArtwork(source, settings, body);
    const generated = isGenerated(body);
    const keycap = generated ? keycapConfig(body) : null;
    const shape = generated ? baseShape(settings) : null;
    const { cap, artwork_area: area } = generated ? keycapLayout(keycap, shape) : body;
    const scaled = downscale(image, MAX_SIDE[quality] ?? MAX_SIDE.preview);

    // Millimetres per working pixel: fit the artwork inside the artwork area, then apply the user's scale.
    const fit = Math.min(area.width / image.width, area.height / image.height) * settings.scale;
    const mmPerPx = (image.width * fit) / scaled.width;
    const artWidth = scaled.width * mmPerPx;
    const artHeight = scaled.height * mmPerPx;
    const frame = {
        left: area.cx + settings.offset_x - artWidth / 2,
        top: area.cy + settings.offset_y + artHeight / 2,
        mmPerPx,
    };

    const palette = settings.colors.map((color, index) => ({
        index,
        hex: color.hex.toUpperCase(),
        rgb: hexToRgb(color.hex),
        enabled: color.enabled !== false,
        height_mm: color.height_mm,
    }));

    const labels = buildLabels(scaled, palette);
    if (cap) applyCapMask(labels, scaled.width, scaled.height, cap, frame);

    const notes = [];
    const minArea = Math.max(4, Math.round((body.min_feature / mmPerPx) ** 2));
    const merged = cleanSpecks(labels, scaled.width, scaled.height, minArea);
    if (merged > 0) {
        notes.push(
            `${merged} tiny detail${merged === 1 ? ' was' : 's were'} merged into the surroundings because ${merged === 1 ? 'it was' : 'they were'} too small to print.`,
        );
    }

    // Thin details: checked on a small copy so the live preview stays fast.
    const factor = Math.max(1, Math.ceil(Math.max(scaled.width, scaled.height) / 256));
    const coarse = subsample(labels, scaled.width, scaled.height, factor);
    const coarseMm = mmPerPx * factor;
    const radius = Math.floor(body.min_feature / coarseMm / 2);
    for (const color of palette) {
        if (!color.enabled) continue;
        const lost = thinResidue(coarse.labels, coarse.width, coarse.height, color.index, radius);
        if (lost * coarseMm * coarseMm > body.min_feature * body.min_feature) {
            notes.push(`Colour ${color.index + 1} (${color.hex}) has details thinner than ${body.min_feature} mm that may not print cleanly.`);
        }
    }

    const outlineIndex = palette.length;
    if (settings.outline_mm > 0) {
        paintOutline(labels, scaled.width, scaled.height, Math.max(1, Math.round(settings.outline_mm / mmPerPx / 2)), outlineIndex);
    }

    const counts = new Uint32Array(outlineIndex + 1);
    for (const label of labels) if (label !== BG) counts[label]++;

    const polygons = {};
    for (let index = 0; index <= outlineIndex; index++) {
        if (counts[index] === 0) continue;
        polygons[index] = toMm(traceLabel(labels, scaled.width, scaled.height, index), frame);
    }

    for (const color of palette) {
        if (color.enabled && !polygons[color.index])
            notes.push(`Colour ${color.index + 1} (${color.hex}) does not appear on the cap, so it was left out.`);
    }

    let keycapShape;
    const blockers = [];
    let traced = null;
    if (generated && shape === 'trace') {
        if (
            settings.remove_background !== false &&
            clearCount(source) < source.width * source.height * 0.02 &&
            clearCount(image) === clearCount(source)
        ) {
            notes.push(NO_BACKGROUND_NOTE);
        }
        traced = traceOutline({ image: scaled, frame, keycap });
        keycapShape = { outline: traced.outline, stem: traced.stem };
        notes.push(...traced.notes);
        if (traced.stemRadius < stemClearance(keycap)) blockers.push(STEM_BLOCKER);
    } else if (generated) {
        keycapShape = { outline: shape === 'circle' ? circleOutline(keycap) : squareOutline(keycap), stem: { x: 0, y: 0 } };
    }
    if (generated) {
        // The switch base's pocket and outline grow from the cap outline. The fit is checked only once the stem fits,
        // so the user sees one problem at a time.
        const { clearance, wall } = keycap.base;
        const grow = (distance) => (shape === 'trace' ? traced.grow(distance) : growOutline(keycapShape.outline, shape, keycap, distance));
        keycapShape.pocketOutline = grow(clearance);
        keycapShape.baseOutline = grow(clearance + wall);
        const fits = baseFits({
            cap: [{ outer: keycapShape.outline, holes: [] }],
            base: [{ outer: keycapShape.baseOutline, holes: [] }],
            stem: keycapShape.stem,
            keycap,
        });
        if (blockers.length === 0 && !fits) blockers.push(BASE_BLOCKER);
    }
    if (generated && settings.hollow_cap === true) {
        keycapShape.cavity = hollowCavity({ shape, ...keycapShape, keycap, trace: traced });
        if (keycapShape.cavity.length === 0) {
            if (blockers.length === 0) notes.push(HOLLOW_NOTE);
        } else if (blockers.length === 0) prepareHollowCap(keycapShape, keycap, notes);
    }
    if (generated && blockers.length === 0) prepareBase(keycapShape, keycap, capInsertion(settings), blockers);

    return { palette, polygons, outlineIndex, notes, mmPerPx, frame, cap: keycapShape, blockers };
}

/**
 * Build the switch base for `cap` = {pocketOutline, baseOutline, stem} once, upright in its own frame (z 0 at its
 * bottom, the rim at `baseTop(keycap, insertion)`), and keep it as `cap.baseBody` when it is a verified closed
 * manifold; otherwise set `cap.baseBody = null` and add the blocker (an unverified base is never exported). The
 * insertion it was built for is kept as `cap.baseInsertion`; `buildParts` rebuilds the base when the settings ask for
 * another one.
 */
export function prepareBase(cap, keycap, insertion, blockers) {
    cap.baseInsertion = insertion;
    cap.baseBody = buildBase({ pocket: cap.pocketOutline, outline: cap.baseOutline, stem: cap.stem, keycap, insertion });
    if (!cap.baseBody) blockers.push(BASE_FAILED_BLOCKER);

    return cap;
}

/**
 * The base placed for a layout, always upright (only translated). `assembled`: moved down so its plate top is at
 * z -rest_gap, i.e. `rest_gap` under the cap's bottom face, and the rim at cap_insertion x the cap height.
 * `print`: z 0 on the bed, moved in +x by the base outline's width plus `spacing` so it clears the cap.
 */
function placeBase(base, baseOutline, config, layout) {
    const { under, plate, rest_gap: restGap, spacing } = config;
    let [dx, dz] = [0, -(restGap + under + plate)];
    if (layout === 'print') {
        const xs = baseOutline.map(([x]) => x);
        [dx, dz] = [Math.max(...xs) - Math.min(...xs) + spacing, 0];
    }
    const positions = new Float32Array(base.positions.length);
    for (let i = 0; i < positions.length; i += 3) {
        positions[i] = base.positions[i] + dx;
        positions[i + 1] = base.positions[i + 1];
        positions[i + 2] = base.positions[i + 2] + dz;
    }

    return { ...base, positions, indices: Uint32Array.from(base.indices) };
}

/**
 * Build the hollow cap for `cap` = {outline, stem, cavity} once and keep it as `cap.hollowBody` when it is a verified
 * closed manifold; otherwise set `cap.hollowBody = null` and add the fallback note (the solid cap is used).
 */
export function prepareHollowCap(cap, keycap, notes) {
    cap.hollowBody = buildHollowCapBody({ outline: cap.outline, cavity: cap.cavity, stem: cap.stem, keycap });
    if (!cap.hollowBody) notes.push(HOLLOW_FAILED_NOTE);

    return cap;
}

/**
 * The fast half: extrude the traced polygons to their heights. Re-run it when only the heights change.
 * For a generated keycap body the cap (kind `body`, named `Cap`) and then the switch base (kind `base`, named
 * `Base`) come last, the base placed for `layout` (see `placeBase`; unknown values mean `assembled`); an analysis
 * with blockers throws the first one.
 * @param {{layout?: 'assembled'|'print'}} [options]
 * @returns {{kind: 'colour'|'outline'|'body'|'base', name: string, hex: string, positions: Float32Array, indices: Uint32Array}[]}
 */
export function buildParts(analysis, settings, body, { layout = 'assembled' } = {}) {
    const generated = isGenerated(body);
    const keycap = generated ? keycapConfig(body) : null;
    if (generated && analysis.blockers?.length) throw new Error(analysis.blockers[0]);

    const top = generated ? keycap.height : body.cap.top_z;
    const parts = [];
    let tallest = 0.1;

    for (const color of analysis.palette) {
        const polygons = analysis.polygons[color.index];
        if (!color.enabled || !polygons) continue;
        // The analysis is frozen; the current heights come from the settings so height edits need no re-analysis.
        const height = clamp(settings.colors[color.index]?.height_mm ?? color.height_mm, 0.1, body.max_layer_height);
        tallest = Math.max(tallest, height);
        parts.push({
            kind: 'colour',
            name: `Colour ${color.index + 1} ${color.hex}`,
            hex: color.hex,
            ...extrudePolygons(polygons, top, top + height),
        });
    }

    const outline = analysis.polygons[analysis.outlineIndex];
    if (outline) {
        const hex = (settings.outline_hex ?? '#111111').toUpperCase();
        parts.push({ kind: 'outline', name: `Outline ${hex}`, hex, ...extrudePolygons(outline, top, top + tallest) });
    }

    if (generated) {
        const { outline: capOutline, stem, hollowBody, baseOutline } = analysis.cap;
        // The hollow cap is built and verified during analysis (it does not depend on the colour heights); turning
        // hollow_cap off needs no re-analysis, turning it on does.
        parts.push(settings.hollow_cap === true && hollowBody ? { ...hollowBody } : buildKeycapBody({ outline: capOutline, stem, keycap }));
        // So is the base, for the insertion the analysis saw; a different insertion rebuilds (and re-verifies) it on a
        // copy, leaving the analysis untouched. Only its placement depends on the layout.
        const insertion = capInsertion(settings);
        let { baseBody } = analysis.cap;
        if (insertion !== analysis.cap.baseInsertion) ({ baseBody } = prepareBase({ ...analysis.cap }, keycap, insertion, []));
        if (!baseBody) throw new Error(BASE_FAILED_BLOCKER);
        parts.push(placeBase(baseBody, baseOutline, keycap.base, LAYOUTS.includes(layout) ? layout : 'assembled'));
    }

    return parts;
}

/** The cap's outline as a counter-clockwise ring in mm, centred on the origin. */
export function capPolygon(cap, segments = 8) {
    const hw = cap.width / 2;
    const hh = cap.height / 2;

    if (cap.shape === 'circle') {
        return Array.from({ length: 72 }, (_, i) => [hw * Math.cos((2 * Math.PI * i) / 72), hh * Math.sin((2 * Math.PI * i) / 72)]);
    }

    const r = cap.shape === 'rounded' ? Math.min(cap.corner_radius ?? 0, hw, hh) : 0;
    if (r <= 0)
        return [
            [hw, -hh],
            [hw, hh],
            [-hw, hh],
            [-hw, -hh],
        ];

    const corners = [
        [hw - r, -(hh - r), -90],
        [hw - r, hh - r, 0],
        [-(hw - r), hh - r, 90],
        [-(hw - r), -(hh - r), 180],
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

/** A plain grey slab in the cap's shape, drawn under the artwork in the editor preview only. */
export function bodyProxyPart(cap) {
    return { kind: 'body', name: 'Body preview', hex: '#9CA3AF', ...extrudePolygons([{ outer: capPolygon(cap), holes: [] }], 0, cap.top_z) };
}
