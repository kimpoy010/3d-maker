/** Test helpers: tiny synthetic images, a sample body config and a vertical-ray probe for shells. */

import assert from 'node:assert/strict';

/** Material intervals [[zIn, zOut], ...] along a vertical ray at (px, py), checking each enters down and leaves up. */
export function materialAlong(mesh, px, py) {
    const v = (i) => [mesh.positions[3 * i], mesh.positions[3 * i + 1], mesh.positions[3 * i + 2]];
    const hits = [];
    for (let t = 0; t < mesh.indices.length; t += 3) {
        const [a, b, c] = [v(mesh.indices[t]), v(mesh.indices[t + 1]), v(mesh.indices[t + 2])];
        const det = (b[0] - a[0]) * (c[1] - a[1]) - (b[1] - a[1]) * (c[0] - a[0]);
        if (Math.abs(det) < 1e-12) continue; // vertical wall
        const u = ((px - a[0]) * (c[1] - a[1]) - (py - a[1]) * (c[0] - a[0])) / det;
        const w = ((b[0] - a[0]) * (py - a[1]) - (b[1] - a[1]) * (px - a[0])) / det;
        if (u < 0 || w < 0 || u + w > 1) continue;
        hits.push({ z: a[2] + u * (b[2] - a[2]) + w * (c[2] - a[2]), up: det > 0 });
    }
    hits.sort((p, q) => p.z - q.z);

    const intervals = [];
    for (let i = 0; i < hits.length; i += 2) {
        assert.equal(hits[i].up, false, 'enters through a downward face');
        assert.equal(hits[i + 1]?.up, true, 'leaves through an upward face');
        intervals.push([hits[i].z, hits[i + 1].z]);
    }

    return intervals;
}

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

/** Signed distance to a rounded rectangle centred on (cx, cy) with half sizes hw, hh and corner radius r. */
function roundedRectDistance(x, y, cx, cy, hw, hh, r) {
    const qx = Math.abs(x - cx) - (hw - r);
    const qy = Math.abs(y - cy) - (hh - r);

    return Math.hypot(Math.max(qx, 0), Math.max(qy, 0)) + Math.min(Math.max(qx, qy), 0) - r;
}

/**
 * A logo-like test image on a transparent background, `size` px square: a ring, a rounded-square monitor with a
 * stand inside it, and a dot touching the ring. Edges are anti-aliased (alpha ramps over about one pixel).
 */
export function logoArtwork(size = 500) {
    const s = size / 500;

    return makeImage(size, size, (px, py) => {
        const x = (px + 0.5) / s;
        const y = (py + 0.5) / s;
        const d = Math.hypot(x - 250, y - 250);
        const ring = Math.max(d - 230, 200 - d);
        const frame = Math.max(roundedRectDistance(x, y, 250, 230, 120, 85, 20), -roundedRectDistance(x, y, 250, 230, 100, 65, 10));
        const stand = Math.min(roundedRectDistance(x, y, 250, 335, 15, 22, 0), roundedRectDistance(x, y, 250, 360, 60, 7, 3));
        const dot = Math.hypot(x - (250 + 238 * Math.SQRT1_2), y - (250 - 238 * Math.SQRT1_2)) - 22;
        const monitor = Math.min(frame, stand);
        const nearest = Math.min(ring, monitor, dot);
        const alpha = Math.round(255 * Math.max(0, Math.min(1, 0.5 - nearest * s)));
        if (alpha === 0) return null;
        const rgb = nearest === dot ? [220, 38, 38] : nearest === ring ? [29, 78, 216] : [17, 17, 17];

        return [...rgb, alpha];
    });
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

/** A generated Cherry MX keycap body with the default keycap sizes. */
export const KEYCAP_BODY = {
    generator: 'mx_keycap',
    cap: { shape: 'rounded', width: 30, height: 30, corner_radius: 4, top_z: 8, inset: 0 },
    artwork_area: { cx: 0, cy: 0, width: 26, height: 26 },
    max_layer_height: 3,
    min_feature: 0.8,
    keycap: {},
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
