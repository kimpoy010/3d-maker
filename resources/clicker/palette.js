import { rgbToHex } from './color.js';

const distance = (p, c) => (p[0] - c[0]) ** 2 + (p[1] - c[1]) ** 2 + (p[2] - c[2]) ** 2;

function nearest(pixel, centres) {
    let best = 0;
    let bestDist = Infinity;
    centres.forEach((centre, i) => {
        const d = distance(pixel, centre);
        if (d < bestDist) {
            bestDist = d;
            best = i;
        }
    });

    return best;
}

function mean(points) {
    const sum = [0, 0, 0];
    for (const p of points) {
        sum[0] += p[0];
        sum[1] += p[1];
        sum[2] += p[2];
    }

    return sum.map((v) => v / points.length);
}

/**
 * Suggest `k` colours for an image. Median cut picks the starting colours and a few k-means passes
 * settle them, so flat colours come out exact. Deterministic: the same image always gives the same
 * palette. Transparent pixels (alpha < 128) are ignored. Returns hex strings, most common first.
 *
 * @param {{data: Uint8Array|Uint8ClampedArray, width: number, height: number}} image RGBA pixels
 * @param {number} k how many colours to return (fewer when the image has fewer distinct colours)
 * @param {{ignoreEdge?: boolean}} [options] ignoreEdge skips opaque pixels 4-adjacent to a transparent one (the
 *   anti-aliased halo left after a background was removed), unless that would leave nothing
 * @returns {string[]}
 */
export function suggestPalette(image, k, options = {}) {
    const { data, width, height } = image;
    const total = width * height;
    const stride = Math.max(1, Math.floor(total / 60000));

    const collect = (skipEdge) => {
        const found = [];
        for (let i = 0; i < total; i += stride) {
            const o = i * 4;
            if (data[o + 3] < 128) continue;
            if (skipEdge) {
                const x = i % width;
                if (
                    (x > 0 && data[o - 4 + 3] < 128) ||
                    (x < width - 1 && data[o + 4 + 3] < 128) ||
                    (i >= width && data[o - width * 4 + 3] < 128) ||
                    (i < total - width && data[o + width * 4 + 3] < 128)
                )
                    continue;
            }
            found.push([data[o], data[o + 1], data[o + 2]]);
        }

        return found;
    };
    let pixels = options.ignoreEdge ? collect(true) : [];
    if (pixels.length === 0) pixels = collect(false);
    if (pixels.length === 0) return [];

    const boxes = [pixels];

    while (boxes.length < k) {
        let pick = -1;
        let pickRange = 0;
        let pickChannel = 0;

        boxes.forEach((box, index) => {
            if (box.length < 2) return;
            for (let c = 0; c < 3; c++) {
                let lo = 255;
                let hi = 0;
                for (const p of box) {
                    if (p[c] < lo) lo = p[c];
                    if (p[c] > hi) hi = p[c];
                }
                if (hi - lo > pickRange) {
                    pickRange = hi - lo;
                    pick = index;
                    pickChannel = c;
                }
            }
        });

        if (pick === -1) break; // nothing left to split

        const box = boxes[pick].slice().sort((a, b) => a[pickChannel] - b[pickChannel]);
        const mid = box.length >> 1;
        boxes.splice(pick, 1, box.slice(0, mid), box.slice(mid));
    }

    let centres = boxes.filter((box) => box.length > 0).map(mean);

    for (let pass = 0; pass < 6; pass++) {
        const groups = centres.map(() => []);
        for (const p of pixels) groups[nearest(p, centres)].push(p);
        centres = centres.map((centre, i) => (groups[i].length > 0 ? mean(groups[i]) : centre));
    }

    const counts = centres.map(() => 0);
    for (const p of pixels) counts[nearest(p, centres)]++;

    const ranked = centres
        .map((centre, i) => ({ hex: rgbToHex(centre), count: counts[i] }))
        .filter((entry) => entry.count > 0)
        .sort((a, b) => b.count - a.count)
        .map((entry) => entry.hex);

    return [...new Set(ranked)]; // most common first, never two identical colours
}
