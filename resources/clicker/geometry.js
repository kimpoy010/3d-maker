import { hexToRgb } from './color.js';
import { buildKeycapBody, circleOutline, keycapConfig, squareOutline, stemClearance } from './keycap.js';
import { extrudePolygons, toMm } from './mesh.js';
import { BG, applyCapMask, buildLabels, cleanSpecks, downscale, paintOutline, thinResidue } from './raster.js';
import { traceOutline } from './trace-outline.js';
import { traceLabel } from './trace.js';

export { suggestPalette } from './palette.js';

/** Longest side of the working pixel grid. */
export const MAX_SIDE = { preview: 1024, final: 2048 };

export const BASE_SHAPES = ['square', 'circle', 'trace'];

export const STEM_BLOCKER = 'This shape is too thin to hold the switch stem. Make the artwork larger or choose Square or Circle.';

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
 * The slow half of the pipeline: pixels in, millimetre polygons out. Re-run it when the artwork,
 * the palette, the scale, the position or the outline change.
 *
 * @param {{data: Uint8Array|Uint8ClampedArray, width: number, height: number}} image RGBA pixels
 * @param {object} settings {colors: {hex, height_mm, enabled}[], scale, offset_x, offset_y, outline_mm, outline_hex}
 * @param {object} body {cap, artwork_area, max_layer_height, min_feature}, or a generated keycap body with
 *   {generator: 'mx_keycap', keycap, max_layer_height, min_feature}; then settings.base_shape picks the outline
 * @param {'preview'|'final'} quality
 * @returns analysis; for generated bodies `cap` = {outline, stem} and `blockers` lists reasons the cap cannot be built
 */
export function analyzeArtwork(image, settings, body, quality = 'preview') {
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
    if (generated && shape === 'trace') {
        const traced = traceOutline({ image: scaled, frame, keycap });
        keycapShape = { outline: traced.outline, stem: traced.stem };
        notes.push(...traced.notes);
        if (traced.stemRadius < stemClearance(keycap)) blockers.push(STEM_BLOCKER);
    } else if (generated) {
        keycapShape = { outline: shape === 'circle' ? circleOutline(keycap) : squareOutline(keycap), stem: { x: 0, y: 0 } };
    }

    return { palette, polygons, outlineIndex, notes, mmPerPx, frame, cap: keycapShape, blockers };
}

/**
 * The fast half: extrude the traced polygons to their heights. Re-run it when only the heights change.
 * For a generated keycap body the keycap itself comes last; an analysis with blockers throws the first one.
 * @returns {{kind: 'colour'|'outline'|'body', name: string, hex: string, positions: Float32Array, indices: Uint32Array}[]}
 */
export function buildParts(analysis, settings, body) {
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

    if (generated) parts.push(buildKeycapBody({ outline: analysis.cap.outline, stem: analysis.cap.stem, keycap }));

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
