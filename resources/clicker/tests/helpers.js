/** Test helpers: tiny synthetic images and a sample body config. */

/** Build an RGBA image; `paint(x, y)` returns [r, g, b] or [r, g, b, a], or nothing for transparent. */
export function makeImage(width, height, paint) {
    const data = new Uint8ClampedArray(width * height * 4);
    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            const color = paint(x, y);
            if (!color) continue;
            data.set(color.length === 3 ? [...color, 255] : color, (y * width + x) * 4);
        }
    }

    return { data, width, height };
}

/** A label grid from rows of text, e.g. ['.##', '#..'] where '.' is background (255) and digits are labels. */
export function gridFromRows(rows) {
    const height = rows.length;
    const width = rows[0].length;
    const labels = new Uint8Array(width * height);
    rows.forEach((row, y) => {
        [...row].forEach((ch, x) => {
            labels[y * width + x] = ch === '.' ? 255 : Number(ch);
        });
    });

    return { labels, width, height };
}

export const BODY = {
    cap: { shape: 'rounded', width: 40, height: 50, corner_radius: 6, top_z: 6, inset: 0.5 },
    artwork_area: { cx: 0, cy: 0, width: 34, height: 44 },
    max_layer_height: 3,
    min_feature: 0.8,
};

export const SETTINGS = {
    colors: [
        { hex: '#1D4ED8', height_mm: 1, enabled: true },
        { hex: '#DC2626', height_mm: 2, enabled: true },
        { hex: '#FFFFFF', height_mm: 3, enabled: true },
    ],
    scale: 1,
    offset_x: 0,
    offset_y: 0,
    outline_mm: 0,
    outline_hex: '#111111',
};

/** 200 x 200: a blue square with a red disc and a white dot, on a transparent background. */
export function sampleArtwork() {
    return makeImage(240, 240, (x, y) => {
        if (x < 20 || x >= 220 || y < 20 || y >= 220) return null;
        if ((x - 120) ** 2 + (y - 120) ** 2 < 40 ** 2) return (x - 120) ** 2 + (y - 120) ** 2 < 12 ** 2 ? [255, 255, 255] : [220, 38, 38];

        return [29, 78, 216];
    });
}
