/** Pixel-grid operations. Labels are a Uint8Array of palette indexes; BG marks "no material". */

export const BG = 255;

/** Area-average downscale so the longest side is at most `maxSide`. Returns the input when already small. */
export function downscale(image, maxSide) {
    const { data, width, height } = image;
    const longest = Math.max(width, height);
    if (longest <= maxSide) return image;

    const f = maxSide / longest;
    const nw = Math.max(1, Math.round(width * f));
    const nh = Math.max(1, Math.round(height * f));
    const out = new Uint8ClampedArray(nw * nh * 4);

    for (let y = 0; y < nh; y++) {
        const y0 = Math.floor((y * height) / nh);
        const y1 = Math.max(y0 + 1, Math.floor(((y + 1) * height) / nh));
        for (let x = 0; x < nw; x++) {
            const x0 = Math.floor((x * width) / nw);
            const x1 = Math.max(x0 + 1, Math.floor(((x + 1) * width) / nw));
            let r = 0;
            let g = 0;
            let b = 0;
            let a = 0;
            let n = 0;
            for (let yy = y0; yy < y1; yy++) {
                for (let xx = x0; xx < x1; xx++) {
                    const o = (yy * width + xx) * 4;
                    const alpha = data[o + 3];
                    r += data[o] * alpha;
                    g += data[o + 1] * alpha;
                    b += data[o + 2] * alpha;
                    a += alpha;
                    n++;
                }
            }
            const o = (y * nw + x) * 4;
            if (a > 0) {
                out[o] = r / a;
                out[o + 1] = g / a;
                out[o + 2] = b / a;
            }
            out[o + 3] = a / n;
        }
    }

    return { data: out, width: nw, height: nh };
}

/**
 * Snap every pixel to the nearest palette colour. Transparent pixels, and pixels whose nearest colour
 * is disabled, become BG. `palette` is a list of {rgb: [r, g, b], enabled: boolean}.
 */
export function buildLabels(image, palette) {
    const { data, width, height } = image;
    const labels = new Uint8Array(width * height).fill(BG);

    for (let i = 0; i < width * height; i++) {
        const o = i * 4;
        if (data[o + 3] < 128) continue;
        let best = -1;
        let bestDist = Infinity;
        for (let c = 0; c < palette.length; c++) {
            const [r, g, b] = palette[c].rgb;
            const dr = data[o] - r;
            const dg = data[o + 1] - g;
            const db = data[o + 2] - b;
            const d = dr * dr + dg * dg + db * db;
            if (d < bestDist) {
                bestDist = d;
                best = c;
            }
        }
        if (best >= 0 && palette[best].enabled) labels[i] = best;
    }

    return labels;
}

/** True when the point (x, y), in millimetres from the cap centre, is inside the cap outline. */
export function insideCap(cap, x, y) {
    const hw = cap.width / 2 - (cap.inset ?? 0);
    const hh = cap.height / 2 - (cap.inset ?? 0);
    const ax = Math.abs(x);
    const ay = Math.abs(y);

    if (cap.shape === 'circle') return (ax / hw) ** 2 + (ay / hh) ** 2 <= 1;
    if (ax > hw || ay > hh) return false;
    if (cap.shape === 'rounded') {
        const r = Math.min(cap.corner_radius ?? 0, hw, hh);
        const qx = ax - (hw - r);
        const qy = ay - (hh - r);
        if (qx > 0 && qy > 0) return qx * qx + qy * qy <= r * r;
    }

    return true;
}

/** Turn pixels outside the cap into BG. `frame` = {left, top, mmPerPx}: the artwork's top-left corner in mm. */
export function applyCapMask(labels, width, height, cap, frame) {
    for (let y = 0; y < height; y++) {
        const Y = frame.top - (y + 0.5) * frame.mmPerPx;
        for (let x = 0; x < width; x++) {
            const X = frame.left + (x + 0.5) * frame.mmPerPx;
            if (!insideCap(cap, X, Y)) labels[y * width + x] = BG;
        }
    }
}

/**
 * Merge connected regions (4-neighbour) smaller than `minArea` pixels into their most common
 * neighbouring label. Returns how many regions were merged.
 */
export function cleanSpecks(labels, width, height, minArea) {
    const n = width * height;
    const seen = new Uint8Array(n);
    const small = [];
    const stack = [];

    const neighboursOf = (p) => {
        const x = p % width;
        const y = (p - x) / width;
        const around = [];
        if (x > 0) around.push(p - 1);
        if (x < width - 1) around.push(p + 1);
        if (y > 0) around.push(p - width);
        if (y < height - 1) around.push(p + width);

        return around;
    };

    // Pass 1: find the small regions.
    for (let start = 0; start < n; start++) {
        if (seen[start]) continue;
        const label = labels[start];
        let area = 0;
        stack.push(start);
        seen[start] = 1;
        while (stack.length) {
            const p = stack.pop();
            area++;
            for (const q of neighboursOf(p)) {
                if (!seen[q] && labels[q] === label) {
                    seen[q] = 1;
                    stack.push(q);
                }
            }
        }
        if (area < minArea) small.push({ start, area });
    }

    small.sort((a, b) => a.area - b.area);

    // Pass 2: re-flood each small region against the current labels and give it to its biggest neighbour.
    const mark = new Uint8Array(n);
    let merged = 0;
    for (const { start } of small) {
        const label = labels[start];
        const cells = [];
        const neighbours = new Map();
        stack.push(start);
        mark[start] = 1;
        while (stack.length) {
            const p = stack.pop();
            cells.push(p);
            for (const q of neighboursOf(p)) {
                if (labels[q] === label) {
                    if (!mark[q]) {
                        mark[q] = 1;
                        stack.push(q);
                    }
                } else {
                    neighbours.set(labels[q], (neighbours.get(labels[q]) ?? 0) + 1);
                }
            }
        }
        for (const p of cells) mark[p] = 0;
        if (neighbours.size === 0) continue;
        let target = BG;
        let most = -1;
        for (const [l, count] of neighbours) {
            if (count > most) {
                most = count;
                target = l;
            }
        }
        for (const p of cells) labels[p] = target;
        merged++;
    }

    return merged;
}

/** Separable square min/max filter of radius r. Pixels outside the image read as `outside`. */
function slide(mask, width, height, r, pickMin, outside) {
    const tmp = new Uint8Array(mask.length);
    const out = new Uint8Array(mask.length);

    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            let v = pickMin ? 1 : 0;
            for (let d = -r; d <= r; d++) {
                const xx = x + d;
                const s = xx < 0 || xx >= width ? outside : mask[y * width + xx];
                if (pickMin ? s < v : s > v) v = s;
            }
            tmp[y * width + x] = v;
        }
    }
    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            let v = pickMin ? 1 : 0;
            for (let d = -r; d <= r; d++) {
                const yy = y + d;
                const s = yy < 0 || yy >= height ? outside : tmp[yy * width + x];
                if (pickMin ? s < v : s > v) v = s;
            }
            out[y * width + x] = v;
        }
    }

    return out;
}

/**
 * How many pixels of `label` vanish when the shape is opened with a square of radius r, that is the
 * pixels belonging to details thinner than 2r + 1 pixels.
 */
export function thinResidue(labels, width, height, label, r) {
    if (r < 1) return 0;
    const mask = new Uint8Array(labels.length);
    for (let i = 0; i < labels.length; i++) mask[i] = labels[i] === label ? 1 : 0;
    const eroded = slide(mask, width, height, r, true, 1);
    const opened = slide(eroded, width, height, r, false, 0);
    let lost = 0;
    for (let i = 0; i < mask.length; i++) if (mask[i] && !opened[i]) lost++;

    return lost;
}

/**
 * Paint a band of `outlineLabel` along every border between two different non-BG labels, r pixels
 * to each side. Returns the number of pixels painted.
 */
export function paintOutline(labels, width, height, r, outlineLabel) {
    const seed = new Uint8Array(labels.length);
    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            const p = y * width + x;
            const a = labels[p];
            if (a === BG) continue;
            const right = x < width - 1 ? labels[p + 1] : a;
            const down = y < height - 1 ? labels[p + width] : a;
            if (right !== a && right !== BG) {
                seed[p] = 1;
                seed[p + 1] = 1;
            }
            if (down !== a && down !== BG) {
                seed[p] = 1;
                seed[p + width] = 1;
            }
        }
    }
    const band = slide(seed, width, height, r, false, 0);
    let painted = 0;
    for (let i = 0; i < labels.length; i++) {
        if (band[i] && labels[i] !== BG) {
            labels[i] = outlineLabel;
            painted++;
        }
    }

    return painted;
}
