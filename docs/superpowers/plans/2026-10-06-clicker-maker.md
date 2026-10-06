# Clicker Maker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Clicker maker" tool where users upload flat artwork, design a colour-layered fidget clicker in a live 3D preview, and pay once (₱50) to download print files (a 3MF plus a zip of one STL per colour).

**Architecture:** One plain-JavaScript geometry module (`resources/clicker/`) turns artwork pixels into one closed mesh per colour. The browser imports it for the live preview. After the user pays, a queued Laravel job runs the same module through Node (`build.mjs`) to write the final files, so the preview and the printed files always match and the paid files are gated on the server. Money moves through the existing credit ledger with one numbered attempt per unlock.

**Tech Stack:** Laravel 12, Inertia 2 + Vue 3 + TypeScript, Three.js (already installed), Pest 3, MySQL (dev) / SQLite in-memory (tests), Node 24 (`node --test`), new npm packages `earcut` (triangulation), `fflate` (zip), `pngjs` (PNG decoding in Node).

**Spec:** `docs/superpowers/specs/2026-10-06-clicker-maker-design.md`

## Global Constraints

- Currency: customers see pesos (₱). The ledger stores credits with 1 credit = ₱1. Use `App\Support\Money::peso(int)` for PHP messages and `peso()` from `@/lib/money` in Vue.
- Unlock price: `₱50` via `config('credits.clicker_cost')`, env `CREDITS_CLICKER_COST`.
- Designs per user: 20 (`CLICKER_MAX_PER_USER`). Colours per design: 1 to 8. Colour height range: 0.1 mm up to the body's `max_layer_height`.
- Uploads: PNG only on the wire (the browser converts JPG and SVG to PNG first), at most 10 MB, 256 to 4096 px on each side after re-encoding, under 40 megapixels. The server never parses SVG.
- Build: `node --max-old-space-size=768 resources/clicker/build.mjs <job-folder>`, process timeout 90 s, job `$timeout` 120 s, lock `expireAfter` 200 s, queue `retry_after` stays 300 s (job timeout < lock expiry < retry_after).
- Money rules (match the rest of the app): conditional state changes (`WHERE status = ...`), the refund happens in the same transaction as the status change, idempotent via a unique ledger index, `lockForUpdate` is allowed before a charge but no plain SELECT, dispatch and file deletes only after commit, lock order is the clicker row and then the user row.
- Files live on the private `local` disk under `clickers/{id}/`. File routes are owner-only and send `X-Content-Type-Options: nosniff` and `Content-Security-Policy: sandbox`.
- Tests must only run on in-memory SQLite (`tests/TestCase.php` refuses otherwise). Never run `php artisan config:cache` on a dev machine. Run Pint and Prettier only on files you changed, never on whole directories.
- Do not name third-party brands in UI copy, prompts or file names.
- Style: PHP follows the existing code (Pint), Vue/TS follows Prettier (`printWidth` 150, 4 spaces, single quotes). Commit after each task.

## File Structure

**JavaScript geometry (new, `resources/clicker/`)**
- `color.js`: hex/rgb helpers.
- `palette.js`: `suggestPalette(image, k)`.
- `raster.js`: pixel-grid operations (downscale, snap to palette, cap mask, speck merge, thin-detail check, outline band).
- `trace.js`: pixel edge tracing into polygons with holes.
- `mesh.js`: mm conversion, extrusion, mesh checks.
- `stl.js`: STL encode and parse.
- `exports.js`: 3MF and STL-zip writers.
- `geometry.js`: `analyzeArtwork`, `buildParts`, `capPolygon`, `bodyProxyPart`.
- `build.mjs`: the CLI the server job runs.
- `tools/make-placeholder-body.mjs`: writes the development body STL.
- `bodies/placeholder-slab/body.stl`: that STL.
- `tests/*.test.js`: `node --test` suites.

**Backend (new)**
- `database/migrations/2026_10_06_00000{1,2,3}_*.php`: bodies, clickers, ledger columns.
- `app/Enums/ClickerStatus.php`, `app/Models/{ClickerBody,Clicker}.php`, `database/factories/{ClickerBodyFactory,ClickerFactory}.php`.
- `config/clicker.php`, `database/seeders/ClickerBodySeeder.php`.
- `app/Services/Clickers/{ClickerImages,ClickerService,ClickerBuilder,NodeClickerBuilder}.php`, exceptions in `app/Exceptions/`.
- `app/Jobs/BuildClickerFiles.php`.
- `app/Policies/ClickerPolicy.php`, `app/Http/Requests/SaveClickerRequest.php`, `app/Http/Resources/{ClickerResource,ClickerBodyResource}.php`, `app/Http/Controllers/{ClickerController,ClickerFileController}.php`.

**Backend (modified):** `app/Enums/LedgerReason.php`, `app/Models/CreditLedgerEntry.php`, `app/Services/Credits/CreditService.php`, `config/credits.php`, `app/Providers/CreatorServiceProvider.php`, `app/Console/Commands/SweepStaleCreations.php`, `routes/web.php`, `database/seeders/DatabaseSeeder.php`, `tests/Pest.php`, `.env.example`, `README.md`.

**Frontend (new/modified):** `resources/js/lib/clicker.ts`, `resources/js/lib/artwork.ts`, `resources/js/components/ModelViewer.vue`, `resources/js/pages/clickers/{Index,Edit}.vue`, `resources/js/components/AppSidebar.vue`, `resources/js/pages/Credits.vue`, `vite.config.ts`, `tsconfig.json`, `eslint.config.js`.

---

## Part A: The geometry module (JavaScript, no Laravel)

All commands in Part A run from the repository root. The code below was written and run as a prototype: 36 tests pass. Copy it exactly, then format it with Prettier.

### Task 1: Tooling, colours, palette and pixel operations

**Files:**
- Modify: `package.json`, `eslint.config.js`
- Create: `resources/clicker/color.js`, `resources/clicker/palette.js`, `resources/clicker/raster.js`
- Create: `resources/clicker/tests/helpers.js`, `resources/clicker/tests/raster.test.js`

**Interfaces:**
- Produces: `hexToRgb(hex) -> [r,g,b]`, `rgbToHex([r,g,b]) -> '#RRGGBB'`; `suggestPalette(image, k) -> string[]` where `image = {data: Uint8Array|Uint8ClampedArray (RGBA), width, height}`; from `raster.js`: `BG = 255`, `downscale(image, maxSide)`, `buildLabels(image, palette)`, `insideCap(cap, x, y)`, `applyCapMask(labels, w, h, cap, frame)`, `cleanSpecks(labels, w, h, minArea) -> merged count`, `thinResidue(labels, w, h, label, r)`, `paintOutline(labels, w, h, r, outlineLabel)`. Test helpers: `makeImage`, `gridFromRows`, `BODY`, `SETTINGS`, `sampleArtwork`.

- [ ] **Step 1: Install the dependencies and add the test script**

Run: `npm install earcut fflate pngjs`
Expected: three packages added under `dependencies` (tested with earcut 3.2.4, fflate 0.8.3, pngjs 7.0.0).

In `package.json`, add this line to `scripts` (after `"lint"`):

```json
        "test:clicker": "node --test \"resources/clicker/tests/*.test.js\""
```

- [ ] **Step 2: Let ESLint understand Node globals in the new folder**

In `eslint.config.js`, add this object to the `defineConfigWithVueTs(...)` arguments, just before the `prettier` argument:

```js
    {
        files: ['resources/clicker/**/*.{js,mjs}'],
        languageOptions: { globals: { process: 'readonly', console: 'readonly' } },
    },
```

- [ ] **Step 3: Write the test helpers**

Create `resources/clicker/tests/helpers.js`:

```js
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
```

- [ ] **Step 4: Write the failing tests**

Create `resources/clicker/tests/raster.test.js`:

```js
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { hexToRgb, rgbToHex } from '../color.js';
import { suggestPalette } from '../palette.js';
import { BG, buildLabels, cleanSpecks, downscale, insideCap, paintOutline, thinResidue } from '../raster.js';
import { gridFromRows, makeImage } from './helpers.js';

test('hex and rgb convert both ways and reject bad input', () => {
    assert.deepEqual(hexToRgb('#F5A742'), [245, 167, 66]);
    assert.equal(rgbToHex([245, 167, 66]), '#F5A742');
    assert.throws(() => hexToRgb('orange'), /Invalid colour/);
});

test('suggestPalette finds the two colours of a two-colour image, and ignores transparency', () => {
    const image = makeImage(40, 20, (x) => (x < 5 ? null : x < 25 ? [255, 0, 0] : [0, 0, 255]));
    const palette = suggestPalette(image, 2);

    assert.deepEqual([...palette].sort(), ['#0000FF', '#FF0000']);
    assert.equal(suggestPalette(image, 4).length, 2, 'cannot invent colours the image does not have');
    assert.deepEqual(suggestPalette(makeImage(4, 4, () => null), 3), []);
});

test('suggestPalette is deterministic', () => {
    const image = makeImage(60, 60, (x, y) => [(x * 4) % 256, (y * 4) % 256, ((x + y) * 2) % 256]);

    assert.deepEqual(suggestPalette(image, 5), suggestPalette(image, 5));
});

test('downscale keeps colours, halves the size and leaves small images alone', () => {
    const image = makeImage(100, 60, () => [10, 200, 30]);
    const small = downscale(image, 50);

    assert.deepEqual([small.width, small.height], [50, 30]);
    assert.deepEqual([...small.data.slice(0, 4)], [10, 200, 30, 255]);
    assert.equal(downscale(image, 500), image);
});

test('buildLabels snaps to the nearest colour and drops transparent and disabled pixels', () => {
    const image = makeImage(3, 1, (x) => (x === 0 ? [250, 10, 10] : x === 1 ? [10, 10, 240] : null));
    const labels = buildLabels(image, [
        { rgb: [255, 0, 0], enabled: true },
        { rgb: [0, 0, 255], enabled: false },
    ]);

    assert.deepEqual([...labels], [0, BG, BG]);
});

test('insideCap respects the shape, the rounded corners and the inset', () => {
    const rounded = { shape: 'rounded', width: 40, height: 50, corner_radius: 6, inset: 0 };

    assert.ok(insideCap(rounded, 0, 0));
    assert.ok(insideCap(rounded, 19.9, 0));
    assert.ok(!insideCap(rounded, 20.1, 0));
    assert.ok(!insideCap(rounded, 19.9, 24.9), 'the corner is cut off');
    assert.ok(!insideCap({ ...rounded, inset: 1 }, 19.5, 0));
    assert.ok(insideCap({ shape: 'circle', width: 20, height: 20 }, 7, 7));
    assert.ok(!insideCap({ shape: 'circle', width: 20, height: 20 }, 8, 8));
});

test('cleanSpecks merges a tiny region into its surroundings and keeps big ones', () => {
    const { labels, width, height } = gridFromRows(['00000000', '00000000', '000.0000', '00000000', '00111100', '00111100']);
    const merged = cleanSpecks(labels, width, height, 3);

    assert.equal(merged, 1);
    assert.equal(labels[2 * width + 3], 0, 'the lone background pixel was filled');
    assert.equal(labels[4 * width + 2], 1, 'the 8 pixel region stayed');
});

test('thinResidue sees a thin line but not a thick block', () => {
    const line = gridFromRows(['.........', '.........', '.0000000.', '.........', '.........']);
    const block = gridFromRows(['.........', '.0000000.', '.0000000.', '.0000000.', '.........']);

    assert.equal(thinResidue(line.labels, line.width, line.height, 0, 1), 7);
    assert.equal(thinResidue(block.labels, block.width, block.height, 0, 1), 0);
    assert.equal(thinResidue(line.labels, line.width, line.height, 0, 0), 0);
});

test('paintOutline draws a band only where two colours meet', () => {
    const { labels, width, height } = gridFromRows(['0000....', '0000....', '0000....', '0000....', '1111....', '1111....', '1111....', '1111....']);
    const painted = paintOutline(labels, width, height, 1, 2);

    assert.ok(painted > 0);
    assert.equal(labels[3 * width + 1], 2, 'next to the border');
    assert.equal(labels[0 * width + 1], 0, 'far from the border');
    assert.equal(labels[0 * width + 5], BG, 'background is never painted');
});
```

- [ ] **Step 5: Run the tests to verify they fail**

Run: `npm run test:clicker`
Expected: FAIL with `Cannot find module '../color.js'` (or similar).

- [ ] **Step 6: Write the implementation**

Create `resources/clicker/color.js`:

```js
/** Colour helpers shared by the browser editor and the Node builder. */

export function hexToRgb(hex) {
    const match = /^#?([0-9a-f]{6})$/i.exec(String(hex));
    if (!match) throw new Error(`Invalid colour: ${hex}`);
    const n = parseInt(match[1], 16);

    return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
}

export function rgbToHex([r, g, b]) {
    return (
        '#' +
        [r, g, b]
            .map((v) => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0'))
            .join('')
            .toUpperCase()
    );
}
```

Create `resources/clicker/palette.js`:

```js
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
 * @returns {string[]}
 */
export function suggestPalette(image, k) {
    const { data, width, height } = image;
    const total = width * height;
    const stride = Math.max(1, Math.floor(total / 60000));
    const pixels = [];

    for (let i = 0; i < total; i += stride) {
        const o = i * 4;
        if (data[o + 3] >= 128) pixels.push([data[o], data[o + 1], data[o + 2]]);
    }
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
```

Create `resources/clicker/raster.js`:

```js
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
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `npm run test:clicker`
Expected: PASS, 9 tests (all in `raster.test.js`).

- [ ] **Step 8: Format and commit**

```bash
npx prettier --write resources/clicker package.json eslint.config.js
npm run test:clicker
git add package.json package-lock.json eslint.config.js resources/clicker
git commit -m "feat: clicker geometry foundations (colours, palette, pixel operations)"
```

### Task 2: Tracing and meshing

**Files:**
- Create: `resources/clicker/trace.js`, `resources/clicker/mesh.js`
- Test: `resources/clicker/tests/trace-mesh.test.js`

**Interfaces:**
- Consumes: `gridFromRows` from Task 1's helpers.
- Produces: `signedArea(ring)`, `traceLabel(labels, w, h, label) -> {outer, holes}[]` (pixel coordinates, y down); `toMm(polygons, frame)` (frame is `{left, top, mmPerPx}`), `extrudePolygons(polygons, z0, z1) -> {positions: Float32Array, indices: Uint32Array}`, `isClosedManifold(mesh)`, `meshVolume(mesh)`, `bounds(mesh)`.

- [ ] **Step 1: Write the failing tests**

Create `resources/clicker/tests/trace-mesh.test.js`:

```js
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { bounds, extrudePolygons, isClosedManifold, meshVolume, toMm } from '../mesh.js';
import { signedArea, traceLabel } from '../trace.js';
import { gridFromRows } from './helpers.js';

const trace = (rows, label = 0) => {
    const { labels, width, height } = gridFromRows(rows);

    return traceLabel(labels, width, height, label);
};

test('a filled square traces to one four-point outline', () => {
    const polygons = trace(['......', '.0000.', '.0000.', '.0000.', '.0000.', '......']);

    assert.equal(polygons.length, 1);
    assert.equal(polygons[0].outer.length, 4);
    assert.equal(signedArea(polygons[0].outer), 16);
    assert.equal(polygons[0].holes.length, 0);
});

test('a ring traces to an outline with one hole', () => {
    const polygons = trace(['00000', '0...0', '0...0', '0...0', '00000']);

    assert.equal(polygons.length, 1);
    assert.equal(signedArea(polygons[0].outer), 25);
    assert.equal(polygons[0].holes.length, 1);
    assert.equal(signedArea(polygons[0].holes[0]), -9);
});

test('pixels that only touch at a corner become separate shapes', () => {
    const polygons = trace(['0..', '.0.', '...']);

    assert.equal(polygons.length, 2);
    assert.deepEqual(polygons.map((p) => signedArea(p.outer)), [1, 1]);
});

test('an island inside a hole is its own shape', () => {
    const polygons = trace(['0000000', '0.....0', '0.000.0', '0.000.0', '0.....0', '0000000']);

    assert.equal(polygons.length, 2);
    const ring = polygons.find((p) => p.holes.length === 1);
    assert.equal(signedArea(ring.outer), 42);
    assert.equal(signedArea(ring.holes[0]), -20);
    assert.equal(signedArea(polygons.find((p) => p.holes.length === 0).outer), 6);
});

test('only the requested label is traced', () => {
    const polygons = trace(['0011', '0011'], 1);

    assert.equal(polygons.length, 1);
    assert.equal(signedArea(polygons[0].outer), 4);
});

test('extruding a square gives a closed solid with the right volume and bounds', () => {
    const square = { outer: [[0, 0], [4, 0], [4, 4], [0, 4]], holes: [] };
    const mesh = extrudePolygons([square], 6, 8);

    assert.ok(isClosedManifold(mesh));
    assert.ok(Math.abs(meshVolume(mesh) - 32) < 1e-6);
    assert.deepEqual(bounds(mesh), [0, 0, 6, 4, 4, 8]);
});

test('extruding a ring leaves the hole empty', () => {
    const ring = {
        outer: [[0, 0], [6, 0], [6, 6], [0, 6]],
        holes: [[[2, 2], [2, 4], [4, 4], [4, 2]]],
    };
    const mesh = extrudePolygons([ring], 0, 1);

    assert.ok(isClosedManifold(mesh));
    assert.ok(Math.abs(meshVolume(mesh) - 32) < 1e-6); // 36 - 4
});

test('traced pixels convert to millimetres and extrude to the matching volume', () => {
    const { labels, width, height } = gridFromRows(['0000.', '0.00.', '0000.', '.....']);
    const frame = { left: -2, top: 3, mmPerPx: 0.5 };
    const polygons = toMm(traceLabel(labels, width, height, 0), frame);
    const mesh = extrudePolygons(polygons, 1, 3);

    assert.ok(isClosedManifold(mesh));
    // 11 filled pixels x 0.25 mm^2 x 2 mm
    assert.ok(Math.abs(meshVolume(mesh) - 5.5) < 1e-6);
    const [minX, minY, minZ, maxX, maxY, maxZ] = bounds(mesh);
    assert.deepEqual([minX, maxX, minY, maxY, minZ, maxZ], [-2, 0, 1.5, 3, 1, 3]);
});

test('an L shaped region extrudes to a closed solid', () => {
    const { labels, width, height } = gridFromRows(['0...', '0...', '0000']);
    const polygons = toMm(traceLabel(labels, width, height, 0), { left: 0, top: 3, mmPerPx: 1 });
    const mesh = extrudePolygons(polygons, 0, 1);

    assert.ok(isClosedManifold(mesh));
    assert.ok(Math.abs(meshVolume(mesh) - 6) < 1e-6); // 6 filled pixels x 1 mm^2 x 1 mm
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:clicker`
Expected: FAIL with `Cannot find module '../mesh.js'`.

- [ ] **Step 3: Write the implementation**

Create `resources/clicker/trace.js`:

```js
/**
 * Trace the boundary of every region of one label into polygons with holes.
 * Pixel coordinates, y pointing down. Regions are 4-connected, so two pixels that only touch at a
 * corner become separate shapes. Collinear points are dropped; nothing else is simplified, so the
 * outlines are exact and cannot self-intersect.
 */

export function signedArea(ring) {
    let sum = 0;
    for (let i = 0; i < ring.length; i++) {
        const [x1, y1] = ring[i];
        const [x2, y2] = ring[(i + 1) % ring.length];
        sum += x1 * y2 - x2 * y1;
    }

    return sum / 2;
}

function pointInRing(px, py, ring) {
    let inside = false;
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
        const [xi, yi] = ring[i];
        const [xj, yj] = ring[j];
        if (yi > py !== yj > py && px < ((xj - xi) * (py - yi)) / (yj - yi) + xi) inside = !inside;
    }

    return inside;
}

function dropCollinear(points) {
    const n = points.length;
    const kept = [];
    for (let i = 0; i < n; i++) {
        const a = points[(i + n - 1) % n];
        const b = points[i];
        const c = points[(i + 1) % n];
        if ((b[0] - a[0]) * (c[1] - b[1]) - (b[1] - a[1]) * (c[0] - b[0]) !== 0) kept.push(b);
    }

    return kept;
}

/**
 * @param {Uint8Array} labels width * height palette indexes
 * @returns {{outer: number[][], holes: number[][][]}[]} outer rings have positive signed area, holes negative
 */
export function traceLabel(labels, width, height, label) {
    const filled = (x, y) => x >= 0 && y >= 0 && x < width && y < height && labels[y * width + x] === label;
    const sx = [];
    const sy = [];
    const ex = [];
    const ey = [];
    const add = (a, b, c, d) => {
        sx.push(a);
        sy.push(b);
        ex.push(c);
        ey.push(d);
    };

    // Clockwise (on screen) edges around every filled pixel wherever the neighbour is not filled.
    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            if (!filled(x, y)) continue;
            if (!filled(x, y - 1)) add(x, y, x + 1, y);
            if (!filled(x + 1, y)) add(x + 1, y, x + 1, y + 1);
            if (!filled(x, y + 1)) add(x + 1, y + 1, x, y + 1);
            if (!filled(x - 1, y)) add(x, y + 1, x, y);
        }
    }

    const stride = width + 1;
    const outgoing = new Map();
    for (let e = 0; e < sx.length; e++) {
        const key = sy[e] * stride + sx[e];
        const list = outgoing.get(key);
        if (list) list.push(e);
        else outgoing.set(key, [e]);
    }

    const turnRank = (cur, next) => {
        const cross = (ex[cur] - sx[cur]) * (ey[next] - sy[next]) - (ey[cur] - sy[cur]) * (ex[next] - sx[next]);

        return cross > 0 ? 0 : cross === 0 ? 1 : 2; // right turn first, then straight, then left
    };

    const used = new Uint8Array(sx.length);
    const loops = [];

    for (let first = 0; first < sx.length; first++) {
        if (used[first]) continue;
        const startKey = sy[first] * stride + sx[first];
        const points = [];
        let cur = first;

        for (;;) {
            used[cur] = 1;
            points.push([sx[cur], sy[cur]]);
            const endKey = ey[cur] * stride + ex[cur];
            const candidates = (outgoing.get(endKey) ?? []).filter((e) => !used[e]);
            if (endKey === startKey) candidates.push(first);
            if (candidates.length === 0) break;
            let next = candidates[0];
            for (const c of candidates) if (turnRank(cur, c) < turnRank(cur, next)) next = c;
            if (next === first) break;
            cur = next;
        }

        const ring = dropCollinear(points);
        if (ring.length >= 3) loops.push(ring);
    }

    const outers = [];
    const holes = [];
    for (const ring of loops) (signedArea(ring) > 0 ? outers : holes).push(ring);

    const polygons = outers.map((outer) => ({ outer, holes: [], area: signedArea(outer) }));

    for (const hole of holes) {
        // A point a quarter pixel beside the hole's first edge lies in the same outer ring as the hole.
        const px = (hole[0][0] + hole[1][0]) / 2 + 0.25;
        const py = (hole[0][1] + hole[1][1]) / 2 + 0.25;
        let owner = null;
        for (const polygon of polygons) {
            if (pointInRing(px, py, polygon.outer) && (owner === null || polygon.area < owner.area)) owner = polygon;
        }
        if (owner) owner.holes.push(hole);
    }

    return polygons.map(({ outer, holes: h }) => ({ outer, holes: h }));
}
```

Create `resources/clicker/mesh.js`:

```js
import earcut from 'earcut';
import { signedArea } from './trace.js';

/**
 * Convert traced pixel polygons to millimetres (y up). Outer rings come out counter-clockwise and holes
 * clockwise, so the solid is always on the left of travel.
 * `frame` = {left, top, mmPerPx}: where the pixel grid's top-left corner sits, in mm.
 */
export function toMm(polygons, frame) {
    const convert = (ring) => ring.map(([x, y]) => [frame.left + x * frame.mmPerPx, frame.top - y * frame.mmPerPx]);
    const orient = (ring, counterClockwise) => ((signedArea(ring) > 0) === counterClockwise ? ring : ring.slice().reverse());

    return polygons.map(({ outer, holes }) => ({
        outer: orient(convert(outer), true),
        holes: holes.map((hole) => orient(convert(hole), false)),
    }));
}

/**
 * Extrude polygons (mm, y up, outer CCW, holes CW) from z0 to z1 into a closed, consistently wound mesh.
 * @returns {{positions: Float32Array, indices: Uint32Array}}
 */
export function extrudePolygons(polygons, z0, z1) {
    const positions = [];
    const indices = [];

    for (const polygon of polygons) {
        const rings = [polygon.outer, ...polygon.holes];
        const flat = [];
        const holeStarts = [];
        const ringStarts = [];
        let count = 0;

        rings.forEach((ring, ringIndex) => {
            if (ringIndex > 0) holeStarts.push(count);
            ringStarts.push(count);
            for (const [x, y] of ring) {
                flat.push(x, y);
                count++;
            }
        });

        const base = positions.length / 3;
        for (let i = 0; i < count; i++) positions.push(flat[2 * i], flat[2 * i + 1], z0);
        for (let i = 0; i < count; i++) positions.push(flat[2 * i], flat[2 * i + 1], z1);

        const triangles = earcut(flat, holeStarts, 2);
        for (let t = 0; t < triangles.length; t += 3) {
            const a = triangles[t];
            let b = triangles[t + 1];
            let c = triangles[t + 2];
            const cross = (flat[2 * b] - flat[2 * a]) * (flat[2 * c + 1] - flat[2 * a + 1]) - (flat[2 * b + 1] - flat[2 * a + 1]) * (flat[2 * c] - flat[2 * a]);
            if (cross < 0) [b, c] = [c, b];
            indices.push(base + count + a, base + count + b, base + count + c); // top, facing up
            indices.push(base + a, base + c, base + b); // bottom, facing down
        }

        rings.forEach((ring, ringIndex) => {
            const start = ringStarts[ringIndex];
            for (let i = 0; i < ring.length; i++) {
                const a = start + i;
                const b = start + ((i + 1) % ring.length);
                indices.push(base + a, base + b, base + count + b, base + a, base + count + b, base + count + a);
            }
        });
    }

    return { positions: new Float32Array(positions), indices: new Uint32Array(indices) };
}

/** True when every edge is shared by exactly two triangles, once in each direction (a closed, consistently wound mesh). */
export function isClosedManifold({ indices }) {
    const directed = new Map();
    for (let t = 0; t < indices.length; t += 3) {
        for (let k = 0; k < 3; k++) {
            const a = indices[t + k];
            const b = indices[t + ((k + 1) % 3)];
            const key = `${a}>${b}`;
            directed.set(key, (directed.get(key) ?? 0) + 1);
        }
    }
    for (const [key, count] of directed) {
        if (count !== 1) return false;
        const [a, b] = key.split('>');
        if (directed.get(`${b}>${a}`) !== 1) return false;
    }

    return directed.size > 0;
}

/** Signed volume in mm^3 (positive for outward-facing triangles). */
export function meshVolume({ positions, indices }) {
    let volume = 0;
    for (let t = 0; t < indices.length; t += 3) {
        const [ax, ay, az] = [positions[3 * indices[t]], positions[3 * indices[t] + 1], positions[3 * indices[t] + 2]];
        const [bx, by, bz] = [positions[3 * indices[t + 1]], positions[3 * indices[t + 1] + 1], positions[3 * indices[t + 1] + 2]];
        const [cx, cy, cz] = [positions[3 * indices[t + 2]], positions[3 * indices[t + 2] + 1], positions[3 * indices[t + 2] + 2]];
        volume += (ax * (by * cz - bz * cy) - ay * (bx * cz - bz * cx) + az * (bx * cy - by * cx)) / 6;
    }

    return volume;
}

/** Bounding box [minX, minY, minZ, maxX, maxY, maxZ] of a mesh. */
export function bounds({ positions }) {
    const box = [Infinity, Infinity, Infinity, -Infinity, -Infinity, -Infinity];
    for (let i = 0; i < positions.length; i += 3) {
        for (let k = 0; k < 3; k++) {
            if (positions[i + k] < box[k]) box[k] = positions[i + k];
            if (positions[i + k] > box[k + 3]) box[k + 3] = positions[i + k];
        }
    }

    return box;
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:clicker`
Expected: PASS (9 raster tests plus 9 here, 18 in total).

- [ ] **Step 5: Format and commit**

```bash
npx prettier --write resources/clicker
npm run test:clicker
git add resources/clicker
git commit -m "feat: trace pixel regions into polygons and extrude them into closed meshes"
```

### Task 3: STL and the two download formats

**Files:**
- Create: `resources/clicker/stl.js`, `resources/clicker/exports.js`
- Test: `resources/clicker/tests/exports.test.js`

**Interfaces:**
- Consumes: `extrudePolygons` (Task 2).
- Produces: `encodeStl(mesh) -> Uint8Array`, `parseStl(bytes) -> {positions, indices}`; `encodeThreeMf(parts, title) -> Uint8Array`, `stlFileName(part, index)`, `encodeStlZip(parts, notes, folder) -> Uint8Array`. A part is `{kind: 'colour'|'outline'|'body', name, hex, positions, indices}`.

- [ ] **Step 1: Write the failing tests**

Create `resources/clicker/tests/exports.test.js`:

```js
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { strFromU8, unzipSync } from 'fflate';
import { encodeStlZip, encodeThreeMf, stlFileName } from '../exports.js';
import { extrudePolygons } from '../mesh.js';
import { encodeStl, parseStl } from '../stl.js';

const square = (hex, kind = 'colour', name = `Colour ${hex}`) => ({ kind, name, hex, ...extrudePolygons([{ outer: [[0, 0], [2, 0], [2, 2], [0, 2]], holes: [] }], 0, 1) });

test('binary STL has the right size and survives a round trip', () => {
    const mesh = square('#FF0000');
    const bytes = encodeStl(mesh);

    assert.equal(bytes.length, 84 + 50 * (mesh.indices.length / 3));
    const back = parseStl(bytes);
    assert.equal(back.indices.length, mesh.indices.length);
    assert.equal(back.positions.length / 3, 8, 'identical corners are welded back together');
});

test('parseStl reads ASCII files and rejects garbage', () => {
    const ascii = 'solid t\nfacet normal 0 0 1\nouter loop\nvertex 0 0 0\nvertex 1 0 0\nvertex 0 1 0\nendloop\nendfacet\nendsolid t\n';
    const mesh = parseStl(new TextEncoder().encode(ascii));

    assert.equal(mesh.indices.length, 3);
    assert.throws(() => parseStl(new TextEncoder().encode('not a model')), /not valid/);
});

test('the 3MF is a valid package with one coloured object per part', () => {
    const parts = [square('#F5A742'), square('#111111', 'outline', 'Outline #111111'), square('#9CA3AF', 'body', 'Body')];
    const files = unzipSync(encodeThreeMf(parts, 'My <clicker>'));

    assert.deepEqual(Object.keys(files).sort(), ['3D/3dmodel.model', '[Content_Types].xml', '_rels/.rels']);
    const model = strFromU8(files['3D/3dmodel.model']);
    assert.ok(model.includes('unit="millimeter"'));
    assert.ok(model.includes('My &lt;clicker&gt;'), 'titles are escaped');
    assert.equal((model.match(/<object /g) ?? []).length, 3);
    assert.equal((model.match(/<item /g) ?? []).length, 3);
    assert.ok(model.includes('displaycolor="#F5A742FF"'));
    assert.ok(model.includes('name="Colour #F5A742"'));

    const vertices = (model.match(/<vertex /g) ?? []).length;
    assert.equal(vertices, parts.reduce((n, p) => n + p.positions.length / 3, 0));
    const largest = Math.max(...[...model.matchAll(/v[123]="(\d+)"/g)].map((m) => Number(m[1])));
    assert.ok(largest < Math.max(...parts.map((p) => p.positions.length / 3)), 'indices stay inside each object');
});

test('the STL zip holds one file per part and a README with the notes', () => {
    const parts = [square('#F5A742'), square('#9CA3AF', 'body', 'Body')];
    const files = unzipSync(encodeStlZip(parts, ['Colour 2 is too thin.'], 'clicker-7'));

    assert.deepEqual(Object.keys(files).sort(), ['clicker-7/01-colour-f5a742.stl', 'clicker-7/02-body.stl', 'clicker-7/README.txt']);
    assert.equal(stlFileName(parts[1], 1), '02-body.stl');
    const readme = strFromU8(files['clicker-7/README.txt']);
    assert.ok(readme.includes('Colour 2 is too thin.'));
    assert.ok(readme.includes('01-colour-f5a742.stl'));
    assert.equal(files['clicker-7/01-colour-f5a742.stl'].length, 84 + 50 * 12);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:clicker`
Expected: FAIL with `Cannot find module '../exports.js'`.

- [ ] **Step 3: Write the implementation**

Create `resources/clicker/stl.js`:

```js
/** Binary STL writer and a reader for body files (binary or ASCII). */

/** @param {{positions: Float32Array, indices: Uint32Array}} mesh */
export function encodeStl({ positions, indices }) {
    const triangles = indices.length / 3;
    const bytes = new Uint8Array(84 + 50 * triangles);
    const view = new DataView(bytes.buffer);
    const header = 'Clicker maker';
    for (let i = 0; i < header.length; i++) bytes[i] = header.charCodeAt(i);
    view.setUint32(80, triangles, true);

    let offset = 84;
    for (let t = 0; t < triangles; t++) {
        const [a, b, c] = [indices[3 * t], indices[3 * t + 1], indices[3 * t + 2]];
        const v = [a, b, c].map((i) => [positions[3 * i], positions[3 * i + 1], positions[3 * i + 2]]);
        const u = [v[1][0] - v[0][0], v[1][1] - v[0][1], v[1][2] - v[0][2]];
        const w = [v[2][0] - v[0][0], v[2][1] - v[0][1], v[2][2] - v[0][2]];
        const n = [u[1] * w[2] - u[2] * w[1], u[2] * w[0] - u[0] * w[2], u[0] * w[1] - u[1] * w[0]];
        const length = Math.hypot(...n) || 1;
        for (const value of [n[0] / length, n[1] / length, n[2] / length, ...v[0], ...v[1], ...v[2]]) {
            view.setFloat32(offset, value, true);
            offset += 4;
        }
        offset += 2; // attribute byte count
    }

    return bytes;
}

/**
 * Parse an STL file and weld identical vertices so the result is an indexed mesh.
 * @param {Uint8Array} bytes
 * @returns {{positions: Float32Array, indices: Uint32Array}}
 */
export function parseStl(bytes) {
    const soup = [];
    const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    const binaryCount = bytes.length >= 84 ? view.getUint32(80, true) : -1;

    if (binaryCount >= 0 && bytes.length === 84 + 50 * binaryCount) {
        for (let t = 0; t < binaryCount; t++) {
            const base = 84 + 50 * t + 12;
            for (let k = 0; k < 9; k++) soup.push(view.getFloat32(base + 4 * k, true));
        }
    } else {
        const text = new TextDecoder().decode(bytes);
        for (const match of text.matchAll(/vertex\s+(\S+)\s+(\S+)\s+(\S+)/g)) soup.push(Number(match[1]), Number(match[2]), Number(match[3]));
    }

    if (soup.length === 0 || soup.length % 9 !== 0 || soup.some((v) => !Number.isFinite(v))) throw new Error('The STL file is not valid.');

    const positions = [];
    const indices = [];
    const seen = new Map();
    for (let i = 0; i < soup.length; i += 3) {
        const key = `${Math.round(soup[i] * 1e4)},${Math.round(soup[i + 1] * 1e4)},${Math.round(soup[i + 2] * 1e4)}`;
        let index = seen.get(key);
        if (index === undefined) {
            index = positions.length / 3;
            seen.set(key, index);
            positions.push(soup[i], soup[i + 1], soup[i + 2]);
        }
        indices.push(index);
    }

    return { positions: new Float32Array(positions), indices: new Uint32Array(indices) };
}
```

Create `resources/clicker/exports.js`:

```js
import { strToU8, zipSync } from 'fflate';
import { encodeStl } from './stl.js';

const escapeXml = (value) =>
    String(value).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' })[c]);

const num = (value) => String(+value.toFixed(4));

/**
 * A 3MF package with one named, coloured object per part. Opens as a multi-part project in
 * Creality Print, Bambu Studio and PrusaSlicer.
 * @param {{name: string, hex: string, positions: Float32Array, indices: Uint32Array}[]} parts
 */
export function encodeThreeMf(parts, title = 'Clicker') {
    const materials = parts.map((part) => `<base name="${escapeXml(part.name)}" displaycolor="${escapeXml(part.hex)}FF"/>`).join('');

    const objects = parts
        .map((part, index) => {
            const vertices = [];
            for (let i = 0; i < part.positions.length; i += 3) {
                vertices.push(`<vertex x="${num(part.positions[i])}" y="${num(part.positions[i + 1])}" z="${num(part.positions[i + 2])}"/>`);
            }
            const triangles = [];
            for (let i = 0; i < part.indices.length; i += 3) {
                triangles.push(`<triangle v1="${part.indices[i]}" v2="${part.indices[i + 1]}" v3="${part.indices[i + 2]}"/>`);
            }

            return `<object id="${index + 2}" type="model" name="${escapeXml(part.name)}" pid="1" pindex="${index}"><mesh><vertices>${vertices.join('')}</vertices><triangles>${triangles.join('')}</triangles></mesh></object>`;
        })
        .join('');

    const items = parts.map((_, index) => `<item objectid="${index + 2}"/>`).join('');

    const model =
        '<?xml version="1.0" encoding="UTF-8"?>' +
        '<model unit="millimeter" xml:lang="en-US" xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02">' +
        `<metadata name="Title">${escapeXml(title)}</metadata>` +
        `<resources><basematerials id="1">${materials}</basematerials>${objects}</resources>` +
        `<build>${items}</build></model>`;

    return zipSync({
        '[Content_Types].xml': strToU8(
            '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="model" ContentType="application/vnd.ms-package.3dmanufacturing-3dmodel+xml"/></Types>',
        ),
        '_rels/.rels': strToU8(
            '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Target="/3D/3dmodel.model" Id="rel0" Type="http://schemas.microsoft.com/3dmanufacturing/2013/01/3dmodel"/></Relationships>',
        ),
        '3D/3dmodel.model': strToU8(model),
    });
}

/** File name inside the STL zip for one part. */
export function stlFileName(part, index) {
    const slug = part.kind === 'body' ? 'body' : `${part.kind}-${part.hex.replace('#', '').toLowerCase()}`;

    return `${String(index + 1).padStart(2, '0')}-${slug}.stl`;
}

/** A zip of one binary STL per part plus a README with the part list and print notes. */
export function encodeStlZip(parts, notes = [], folder = 'clicker') {
    const files = {};
    const lines = ['Clicker print files', '', 'Parts (one STL each, all share the same origin so they line up when imported together):'];

    parts.forEach((part, index) => {
        const name = stlFileName(part, index);
        files[`${folder}/${name}`] = encodeStl(part);
        lines.push(`- ${name}: ${part.name}`);
    });

    lines.push('', 'Print notes:');
    if (notes.length === 0) lines.push('- None.');
    for (const note of notes) lines.push(`- ${note}`);
    lines.push('');
    files[`${folder}/README.txt`] = strToU8(lines.join('\n'));

    return zipSync(files);
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:clicker`
Expected: PASS (18 earlier tests plus 4 here, 22 in total).

- [ ] **Step 5: Format and commit**

```bash
npx prettier --write resources/clicker
npm run test:clicker
git add resources/clicker
git commit -m "feat: encode clicker parts as STL, a multi-part 3MF and a zip of STLs"
```

### Task 4: The geometry pipeline and the Node build script

**Files:**
- Create: `resources/clicker/geometry.js`, `resources/clicker/build.mjs`
- Test: `resources/clicker/tests/geometry.test.js`, `resources/clicker/tests/build.test.js`

**Interfaces:**
- Consumes: everything from Tasks 1 to 3.
- Produces: `analyzeArtwork(image, settings, body, quality) -> {palette, polygons, outlineIndex, notes, mmPerPx, frame}`, `buildParts(analysis, settings, body) -> parts[]`, `capPolygon(cap, segments)`, `bodyProxyPart(cap)`, `suggestPalette` (re-exported), `MAX_SIDE`. `build.mjs <job-folder>` reads `job.json` (`{settings, body, bodyFiles: [{path, name, hex?}], title, folder}`) and `artwork.png`, and writes `clicker.3mf`, `clicker-stl.zip` and `result.json` into that folder. Exit codes: 0 success, 1 build failure (message on stderr starting `BUILD FAILED:`), 2 missing argument.
- Settings shape: `{colors: [{hex, height_mm, enabled}], scale, offset_x, offset_y, outline_mm, outline_hex}`. Body shape: `{cap: {shape: 'rect'|'rounded'|'circle', width, height, corner_radius, top_z, inset}, artwork_area: {cx, cy, width, height}, max_layer_height, min_feature}`.

- [ ] **Step 1: Write the failing tests**

Create `resources/clicker/tests/geometry.test.js`:

```js
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { analyzeArtwork, bodyProxyPart, buildParts, capPolygon } from '../geometry.js';
import { bounds, isClosedManifold, meshVolume } from '../mesh.js';
import { BODY, SETTINGS, makeImage, sampleArtwork } from './helpers.js';

const build = (settings = SETTINGS, image = sampleArtwork(), quality = 'preview') => {
    const analysis = analyzeArtwork(image, settings, BODY, quality);

    return { analysis, parts: buildParts(analysis, settings, BODY) };
};

test('the sample artwork becomes three closed colour parts sitting on the cap', () => {
    const { parts } = build();

    assert.equal(parts.length, 3);
    assert.deepEqual(parts.map((p) => p.hex), ['#1D4ED8', '#DC2626', '#FFFFFF']);
    for (const part of parts) {
        assert.ok(isClosedManifold(part), `${part.name} is a closed solid`);
        const [minX, minY, minZ, maxX, maxY, maxZ] = bounds(part);
        assert.ok(minX >= -20 && maxX <= 20 && minY >= -25 && maxY <= 25, 'inside the cap');
        assert.ok(Math.abs(minZ - 6) < 1e-4, 'starts on the cap surface');
        assert.ok(maxZ > minZ);
    }
});

test('each colour rises to its own height, clamped to the body maximum', () => {
    const settings = { ...SETTINGS, colors: SETTINGS.colors.map((c, i) => ({ ...c, height_mm: i === 2 ? 10 : c.height_mm })) };
    const { parts } = build(settings);
    const tops = parts.map((p) => +bounds(p)[5].toFixed(3));

    assert.deepEqual(tops, [7, 8, 9]); // 6 + 1, 6 + 2, 6 + 3 (10 mm clamped to the 3 mm maximum)
});

test('the volume of each part matches the artwork area times its height', () => {
    const { parts, analysis } = build();
    const blue = parts[0];
    const frameArea = 200 * 200 * analysis.mmPerPx ** 2; // the 200 px square, minus the red disc

    assert.ok(meshVolume(blue) > 0);
    assert.ok(meshVolume(blue) < frameArea * 1, 'less than the whole square');
    assert.ok(meshVolume(blue) > frameArea * 0.5, 'but most of it');
});

test('scale and offset move the artwork', () => {
    const half = build({ ...SETTINGS, scale: 0.5 });
    const full = build();
    const width = (parts) => Math.max(...parts.map((p) => bounds(p)[3])) - Math.min(...parts.map((p) => bounds(p)[0]));

    assert.ok(Math.abs(width(half.parts) - width(full.parts) / 2) < 0.2);

    const moved = build({ ...SETTINGS, scale: 0.5, offset_x: 5 });
    assert.ok(Math.min(...moved.parts.map((p) => bounds(p)[0])) > Math.min(...half.parts.map((p) => bounds(p)[0])) + 4.5);
});

test('a disabled colour leaves no part', () => {
    const settings = { ...SETTINGS, colors: SETTINGS.colors.map((c, i) => ({ ...c, enabled: i !== 1 })) };
    const { parts } = build(settings);

    assert.equal(parts.length, 2);
    assert.ok(!parts.some((p) => p.hex === '#DC2626'));
});

test('artwork larger than the cap is cut to the cap outline', () => {
    const tall = makeImage(100, 400, () => [29, 78, 216]);
    const { parts } = build({ ...SETTINGS, colors: [SETTINGS.colors[0]], scale: 1 }, tall);
    const [minX, minY, , maxX, maxY] = bounds(parts[0]);

    assert.ok(minX >= -19.6 && maxX <= 19.6 && minY >= -24.6 && maxY <= 24.6);
});

test('an outline colour adds a part as tall as the tallest colour', () => {
    const { parts } = build({ ...SETTINGS, outline_mm: 1, outline_hex: '#111111' });
    const outline = parts.find((p) => p.kind === 'outline');

    assert.ok(outline);
    assert.ok(isClosedManifold(outline));
    assert.ok(Math.abs(bounds(outline)[5] - 9) < 1e-4);
});

test('tiny specks are merged and the user is told', () => {
    const speck = makeImage(240, 240, (x, y) => {
        if (x === 120 && y === 120) return [220, 38, 38];

        return x < 20 || x >= 220 || y < 20 || y >= 220 ? null : [29, 78, 216];
    });
    const { analysis } = build({ ...SETTINGS, colors: SETTINGS.colors.slice(0, 2) }, speck);

    assert.ok(analysis.notes.some((n) => n.includes('tiny detail')));
});

test('thin details and absent colours produce print notes', () => {
    const lines = makeImage(240, 240, (x, y) => (x >= 20 && x < 220 && y >= 20 && y < 220 ? (y % 40 < 2 ? [220, 38, 38] : [29, 78, 216]) : null));
    const { analysis } = build({ ...SETTINGS, colors: SETTINGS.colors }, lines);

    assert.ok(analysis.notes.some((n) => n.includes('thinner than 0.8 mm')), analysis.notes.join(' | '));
    assert.ok(analysis.notes.some((n) => n.includes('does not appear on the cap')));
});

test('the final quality works on a bigger grid without changing the result much', () => {
    const preview = build(SETTINGS, sampleArtwork(), 'preview');
    const final = build(SETTINGS, sampleArtwork(), 'final');

    const area = (parts) => parts.reduce((sum, p) => sum + meshVolume(p), 0);
    assert.ok(Math.abs(area(preview.parts) - area(final.parts)) / area(final.parts) < 0.02);
});

test('the cap outline and the grey body preview are valid', () => {
    for (const shape of ['rect', 'rounded', 'circle']) {
        const cap = { ...BODY.cap, shape };
        const ring = capPolygon(cap);
        const proxy = bodyProxyPart(cap);

        assert.ok(ring.length >= 4);
        assert.ok(isClosedManifold(proxy), `${shape} body preview is closed`);
        assert.ok(Math.abs(bounds(proxy)[5] - cap.top_z) < 1e-6);
        assert.ok(meshVolume(proxy) > 0);
    }
});
```

Create `resources/clicker/tests/build.test.js`:

```js
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';
import { strFromU8, unzipSync } from 'fflate';
import { PNG } from 'pngjs';
import { analyzeArtwork, buildParts } from '../geometry.js';
import { extrudePolygons } from '../mesh.js';
import { encodeStl } from '../stl.js';
import { BODY, SETTINGS, sampleArtwork } from './helpers.js';

const BUILD = fileURLToPath(new URL('../build.mjs', import.meta.url));

const square = (hex) => ({ kind: 'body', name: 'Body', hex, ...extrudePolygons([{ outer: [[0, 0], [2, 0], [2, 2], [0, 2]], holes: [] }], 0, 1) });

test('build.mjs turns a job folder into print files', () => {
    const dir = mkdtempSync(join(tmpdir(), 'clicker-job-'));
    const image = sampleArtwork();
    const png = new PNG({ width: image.width, height: image.height });
    png.data = Buffer.from(image.data);
    writeFileSync(join(dir, 'artwork.png'), PNG.sync.write(png));

    const bodyStl = join(dir, 'body.stl');
    writeFileSync(bodyStl, encodeStl(square('#9CA3AF')));
    writeFileSync(join(dir, 'job.json'), JSON.stringify({ settings: SETTINGS, body: BODY, bodyFiles: [{ path: bodyStl, name: 'Body' }], title: 'Test clicker', folder: 'clicker-1' }));

    const run = spawnSync(process.execPath, [new URL('../build.mjs', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1'), dir], { encoding: 'utf8' });

    assert.equal(run.status, 0, run.stderr);
    const zip = unzipSync(readFileSync(join(dir, 'clicker-stl.zip')));
    assert.equal(Object.keys(zip).filter((f) => f.endsWith('.stl')).length, 4, 'three colours plus the body');
    const model = strFromU8(unzipSync(readFileSync(join(dir, 'clicker.3mf')))['3D/3dmodel.model']);
    assert.equal((model.match(/<object /g) ?? []).length, 4);
    const result = JSON.parse(readFileSync(join(dir, 'result.json'), 'utf8'));
    assert.equal(result.parts.length, 4);
});

test('build.mjs fails cleanly when there is nothing to print or no job', () => {
    const dir = mkdtempSync(join(tmpdir(), 'clicker-job-'));
    const png = new PNG({ width: 20, height: 20 }); // fully transparent
    writeFileSync(join(dir, 'artwork.png'), PNG.sync.write(png));
    writeFileSync(join(dir, 'job.json'), JSON.stringify({ settings: SETTINGS, body: BODY }));

    const empty = spawnSync(process.execPath, [BUILD, dir], { encoding: 'utf8' });
    assert.equal(empty.status, 1);
    assert.match(empty.stderr, /no printable colour/);

    const missing = spawnSync(process.execPath, [BUILD], { encoding: 'utf8' });
    assert.equal(missing.status, 2);
});

test('analysis plus build is fast enough for a live preview', () => {
    const started = performance.now();
    const analysis = analyzeArtwork(sampleArtwork(), SETTINGS, BODY, 'preview');
    buildParts(analysis, SETTINGS, BODY);

    assert.ok(performance.now() - started < 1500, `took ${Math.round(performance.now() - started)} ms`);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:clicker`
Expected: FAIL with `Cannot find module '../geometry.js'`.

- [ ] **Step 3: Write the implementation**

Create `resources/clicker/geometry.js`:

```js
import { hexToRgb } from './color.js';
import { extrudePolygons, toMm } from './mesh.js';
import { BG, applyCapMask, buildLabels, cleanSpecks, downscale, paintOutline, thinResidue } from './raster.js';
import { traceLabel } from './trace.js';

export { suggestPalette } from './palette.js';

/** Longest side of the working pixel grid. */
export const MAX_SIDE = { preview: 1024, final: 2048 };

const clamp = (value, min, max) => Math.max(min, Math.min(max, value));

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
 * @param {object} body {cap, artwork_area, max_layer_height, min_feature}
 * @param {'preview'|'final'} quality
 */
export function analyzeArtwork(image, settings, body, quality = 'preview') {
    const { cap, artwork_area: area } = body;
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
    applyCapMask(labels, scaled.width, scaled.height, cap, frame);

    const notes = [];
    const minArea = Math.max(4, Math.round((body.min_feature / mmPerPx) ** 2));
    const merged = cleanSpecks(labels, scaled.width, scaled.height, minArea);
    if (merged > 0) {
        notes.push(`${merged} tiny detail${merged === 1 ? ' was' : 's were'} merged into the surroundings because ${merged === 1 ? 'it was' : 'they were'} too small to print.`);
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
        if (color.enabled && !polygons[color.index]) notes.push(`Colour ${color.index + 1} (${color.hex}) does not appear on the cap, so it was left out.`);
    }

    return { palette, polygons, outlineIndex, notes, mmPerPx, frame };
}

/**
 * The fast half: extrude the traced polygons to their heights. Re-run it when only the heights change.
 * @returns {{kind: 'colour'|'outline', name: string, hex: string, positions: Float32Array, indices: Uint32Array}[]}
 */
export function buildParts(analysis, settings, body) {
    const top = body.cap.top_z;
    const parts = [];
    let tallest = 0.1;

    for (const color of analysis.palette) {
        const polygons = analysis.polygons[color.index];
        if (!color.enabled || !polygons) continue;
        const height = clamp(color.height_mm, 0.1, body.max_layer_height);
        tallest = Math.max(tallest, height);
        parts.push({ kind: 'colour', name: `Colour ${color.index + 1} ${color.hex}`, hex: color.hex, ...extrudePolygons(polygons, top, top + height) });
    }

    const outline = analysis.polygons[analysis.outlineIndex];
    if (outline) {
        const hex = (settings.outline_hex ?? '#111111').toUpperCase();
        parts.push({ kind: 'outline', name: `Outline ${hex}`, hex, ...extrudePolygons(outline, top, top + tallest) });
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
    if (r <= 0) return [[hw, -hh], [hw, hh], [-hw, hh], [-hw, -hh]];

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
```

Create `resources/clicker/build.mjs`:

```js
// Builds the paid print files for one clicker design.
// Usage: node build.mjs <job-folder>
// The folder holds job.json and artwork.png; this writes clicker.3mf, clicker-stl.zip and result.json.
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { PNG } from 'pngjs';
import { encodeStlZip, encodeThreeMf } from './exports.js';
import { analyzeArtwork, buildParts } from './geometry.js';
import { parseStl } from './stl.js';

const dir = process.argv[2];
if (!dir) {
    console.error('usage: node build.mjs <job-folder>');
    process.exit(2);
}

try {
    const job = JSON.parse(readFileSync(join(dir, 'job.json'), 'utf8'));
    const png = PNG.sync.read(readFileSync(join(dir, 'artwork.png')));
    const image = { data: png.data, width: png.width, height: png.height };

    const analysis = analyzeArtwork(image, job.settings, job.body, 'final');
    const parts = buildParts(analysis, job.settings, job.body);
    if (parts.length === 0) throw new Error('The artwork has no printable colour on this body.');

    for (const file of job.bodyFiles ?? []) {
        const mesh = parseStl(new Uint8Array(readFileSync(file.path)));
        parts.push({ kind: 'body', name: file.name ?? 'Body', hex: file.hex ?? '#9CA3AF', ...mesh });
    }

    writeFileSync(join(dir, 'clicker.3mf'), encodeThreeMf(parts, job.title ?? 'Clicker'));
    writeFileSync(join(dir, 'clicker-stl.zip'), encodeStlZip(parts, analysis.notes, job.folder ?? 'clicker'));
    writeFileSync(
        join(dir, 'result.json'),
        JSON.stringify({ notes: analysis.notes, parts: parts.map((p) => ({ kind: p.kind, name: p.name, triangles: p.indices.length / 3 })) }),
    );
} catch (error) {
    console.error(`BUILD FAILED: ${error.message}`);
    process.exit(1);
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:clicker`
Expected: PASS, 36 tests in total.

- [ ] **Step 5: Lint, format and commit**

```bash
npx prettier --write resources/clicker
npx eslint resources/clicker
npm run test:clicker
git add resources/clicker
git commit -m "feat: clicker geometry pipeline and the Node script that builds the print files"
```

---

## Part B: Backend (Laravel)

All commands run from the repository root. Tests run with `vendor/bin/pest` (in-memory SQLite). Before running Pest, make sure no cached config exists: `php artisan config:clear`.

### Task 5: Tables, models, ledger reasons and the credit service

**Files:**
- Create: `database/migrations/2026_10_06_000001_create_clicker_bodies_table.php`
- Create: `database/migrations/2026_10_06_000002_create_clickers_table.php`
- Create: `database/migrations/2026_10_06_000003_add_clicker_columns_to_credit_ledger_table.php`
- Create: `app/Enums/ClickerStatus.php`, `app/Models/ClickerBody.php`, `app/Models/Clicker.php`
- Create: `database/factories/ClickerBodyFactory.php`, `database/factories/ClickerFactory.php`
- Modify: `app/Enums/LedgerReason.php`, `app/Models/CreditLedgerEntry.php`, `app/Services/Credits/CreditService.php`, `config/credits.php`, `.env.example`
- Test: `tests/Feature/ClickerModelsTest.php`, `tests/Feature/ClickerCreditsTest.php`

**Interfaces:**
- Produces: `ClickerStatus` (`Draft`, `Building`, `Ready`, `Failed`); `ClickerBody::sampleConfig(): array`; model `ClickerBody` (`slug, name, description, preview_image, model_files (array), config (array), active`, scope `active()`, relation `clickers()`); model `Clicker` (`user_id, clicker_body_id, name, artwork_path, settings (array), thumbnail_path, status (ClickerStatus), build_error, threemf_path, stl_zip_path, downloads_unlocked_at (datetime), unlock_attempts (int), build_started_at (datetime)`, relations `user()`, `body()`, helpers `isUnlocked(): bool`, `directory(): string` returning `clickers/{id}`); `LedgerReason::ClickerDownload` (`clicker_download`) and `ClickerRefund` (`clicker_refund`); `CreditService::spendForClicker(User $user, int $amount, Clicker $clicker, int $attempt): CreditLedgerEntry` and `CreditService::refundClicker(Clicker $clicker, int $attempt): ?CreditLedgerEntry`; config key `credits.clicker_cost` (default 50).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ClickerModelsTest.php`:

```php
<?php

use App\Enums\ClickerStatus;
use App\Enums\LedgerReason;
use App\Models\Clicker;
use App\Models\ClickerBody;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Services\Credits\CreditService;

it('casts a body and a clicker', function () {
    $body = ClickerBody::factory()->create();
    $clicker = Clicker::factory()->create(['clicker_body_id' => $body->id]);

    $fresh = Clicker::find($clicker->id);

    expect($body->fresh()->config['cap']['shape'])->toBe('rounded')
        ->and($body->fresh()->model_files)->toBeArray()
        ->and($body->fresh()->active)->toBeTrue()
        ->and($fresh->status)->toBe(ClickerStatus::Draft)
        ->and($fresh->settings['colors'])->toHaveCount(2)
        ->and($fresh->isUnlocked())->toBeFalse()
        ->and($fresh->directory())->toBe("clickers/{$clicker->id}")
        ->and($fresh->body->is($body))->toBeTrue()
        ->and($fresh->user)->toBeInstanceOf(User::class);
});

it('filters active bodies', function () {
    ClickerBody::factory()->create();
    ClickerBody::factory()->create(['active' => false]);

    expect(ClickerBody::active()->count())->toBe(1);
});

it('is unlocked once downloads_unlocked_at is set', function () {
    $clicker = Clicker::factory()->create(['downloads_unlocked_at' => now()]);

    expect($clicker->fresh()->isUnlocked())->toBeTrue();
});

it('keeps the ledger row but forgets the clicker when the clicker is deleted', function () {
    $clicker = Clicker::factory()->create();
    $credits = app(CreditService::class);
    $credits->grant($clicker->user, 100, LedgerReason::Signup);
    $credits->spendForClicker($clicker->user, 50, $clicker, 1);

    $clicker->delete();

    $row = CreditLedgerEntry::where('reason', LedgerReason::ClickerDownload)->first();
    expect($row)->not->toBeNull()->and($row->clicker_id)->toBeNull();
});
```

Create `tests/Feature/ClickerCreditsTest.php`:

```php
<?php

use App\Enums\LedgerReason;
use App\Exceptions\InsufficientCreditsException;
use App\Models\Clicker;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Services\Credits\CreditService;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function () {
    $this->credits = app(CreditService::class);
    $this->user = User::factory()->create();
    $this->credits->grant($this->user, 120, LedgerReason::Signup);
    $this->clicker = Clicker::factory()->create(['user_id' => $this->user->id]);
});

it('exposes the unlock price in config', function () {
    expect(config('credits.clicker_cost'))->toBe(50);
});

it('charges one numbered attempt and links the ledger row to the clicker', function () {
    $entry = $this->credits->spendForClicker($this->user, 50, $this->clicker, 1);

    expect($entry->delta)->toBe(-50)
        ->and($entry->reason)->toBe(LedgerReason::ClickerDownload)
        ->and($entry->clicker_id)->toBe($this->clicker->id)
        ->and($entry->clicker_attempt)->toBe(1)
        ->and($this->credits->balance($this->user))->toBe(70);
});

it('refuses to charge more than the balance and writes nothing', function () {
    expect(fn () => $this->credits->spendForClicker($this->user, 500, $this->clicker, 1))
        ->toThrow(InsufficientCreditsException::class);

    expect(CreditLedgerEntry::where('reason', LedgerReason::ClickerDownload)->count())->toBe(0)
        ->and($this->credits->balance($this->user))->toBe(120);
});

it('cannot charge the same attempt twice', function () {
    $this->credits->spendForClicker($this->user, 50, $this->clicker, 1);

    expect(fn () => $this->credits->spendForClicker($this->user, 50, $this->clicker, 1))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('refunds an attempt exactly once', function () {
    $this->credits->spendForClicker($this->user, 50, $this->clicker, 1);

    $first = $this->credits->refundClicker($this->clicker, 1);
    $second = $this->credits->refundClicker($this->clicker, 1);

    expect($first->delta)->toBe(50)
        ->and($first->reason)->toBe(LedgerReason::ClickerRefund)
        ->and($second)->toBeNull()
        ->and($this->credits->balance($this->user))->toBe(120);
});

it('refunds nothing for an attempt that was never charged', function () {
    expect($this->credits->refundClicker($this->clicker, 1))->toBeNull();
});

it('lets a second attempt be charged and refunded after the first was refunded', function () {
    $this->credits->spendForClicker($this->user, 50, $this->clicker, 1);
    $this->credits->refundClicker($this->clicker, 1);

    $this->credits->spendForClicker($this->user, 50, $this->clicker, 2);
    expect($this->credits->balance($this->user))->toBe(70);

    $this->credits->refundClicker($this->clicker, 2);
    expect($this->credits->balance($this->user))->toBe(120)
        ->and(CreditLedgerEntry::where('clicker_id', $this->clicker->id)->count())->toBe(4);
});

it('only refunds the attempt it is asked about', function () {
    $this->credits->spendForClicker($this->user, 50, $this->clicker, 1);
    $this->credits->refundClicker($this->clicker, 1);
    $this->credits->spendForClicker($this->user, 50, $this->clicker, 2);

    expect($this->credits->refundClicker($this->clicker, 1))->toBeNull()
        ->and($this->credits->balance($this->user))->toBe(70);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan config:clear && vendor/bin/pest tests/Feature/ClickerModelsTest.php tests/Feature/ClickerCreditsTest.php`
Expected: FAIL with `Class "App\Models\ClickerBody" not found` (or similar).

- [ ] **Step 3: Write the migrations**

Create `database/migrations/2026_10_06_000001_create_clicker_bodies_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clicker_bodies', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('preview_image')->nullable();
            // Paths relative to the project root, inside resources/clicker/bodies/.
            $table->json('model_files');
            // Cap, artwork area and print limits, all in millimetres. See ClickerBody::sampleConfig().
            $table->json('config');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clicker_bodies');
    }
};
```

Create `database/migrations/2026_10_06_000002_create_clickers_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clickers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clicker_body_id')->constrained('clicker_bodies');
            $table->string('name');
            $table->string('artwork_path')->nullable();
            $table->json('settings');
            $table->string('thumbnail_path')->nullable();
            $table->string('status')->default('draft');
            $table->string('build_error')->nullable();
            $table->string('threemf_path')->nullable();
            $table->string('stl_zip_path')->nullable();
            $table->timestamp('downloads_unlocked_at')->nullable();
            // How many times the user has paid to unlock this design (each is one numbered ledger attempt).
            $table->unsignedSmallInteger('unlock_attempts')->default(0);
            // Set when a build starts; the sweeper fails builds that stay "building" too long.
            $table->timestamp('build_started_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'build_started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clickers');
    }
};
```

Create `database/migrations/2026_10_06_000003_add_clicker_columns_to_credit_ledger_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_ledger', function (Blueprint $table) {
            $table->foreignId('clicker_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('clicker_attempt')->nullable();

            // One charge row and at most one refund row per numbered attempt; NULLs never collide.
            $table->unique(['clicker_id', 'reason', 'clicker_attempt']);
        });
    }

    public function down(): void
    {
        Schema::table('credit_ledger', function (Blueprint $table) {
            // FK first: on MySQL the unique index backs the FK and cannot be dropped before it.
            $table->dropForeign(['clicker_id']);
            $table->dropUnique(['clicker_id', 'reason', 'clicker_attempt']);
            $table->dropColumn(['clicker_id', 'clicker_attempt']);
        });
    }
};
```

- [ ] **Step 4: Write the enum, models and factories**

Create `app/Enums/ClickerStatus.php`:

```php
<?php

namespace App\Enums;

enum ClickerStatus: string
{
    case Draft = 'draft';
    case Building = 'building';
    case Ready = 'ready';
    case Failed = 'failed';
}
```

Create `app/Models/ClickerBody.php`:

```php
<?php

namespace App\Models;

use Database\Factories\ClickerBodyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClickerBody extends Model
{
    /** @use HasFactory<ClickerBodyFactory> */
    use HasFactory;

    protected $fillable = ['slug', 'name', 'description', 'preview_image', 'model_files', 'config', 'active'];

    protected function casts(): array
    {
        return ['model_files' => 'array', 'config' => 'array', 'active' => 'boolean'];
    }

    /** @param Builder<ClickerBody> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function clickers(): HasMany
    {
        return $this->hasMany(Clicker::class);
    }

    /**
     * A plain development body: 40 x 50 mm rounded cap whose top surface is 6 mm above the build plate.
     * All values are millimetres. `inset` keeps artwork that far inside the cap edge; `min_feature` is the
     * smallest detail that prints cleanly; `max_layer_height` caps how tall a colour may rise.
     *
     * @return array<string, mixed>
     */
    public static function sampleConfig(): array
    {
        return [
            'cap' => ['shape' => 'rounded', 'width' => 40, 'height' => 50, 'corner_radius' => 6, 'top_z' => 6, 'inset' => 0.5],
            'artwork_area' => ['cx' => 0, 'cy' => 0, 'width' => 34, 'height' => 44],
            'base_thickness' => 1.2,
            'max_layer_height' => 3,
            'min_feature' => 0.8,
            'switch' => 'None (development body)',
        ];
    }
}
```

Create `app/Models/Clicker.php`:

```php
<?php

namespace App\Models;

use App\Enums\ClickerStatus;
use Database\Factories\ClickerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Clicker extends Model
{
    /** @use HasFactory<ClickerFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'clicker_body_id', 'name', 'artwork_path', 'settings', 'thumbnail_path', 'status', 'build_error',
        'threemf_path', 'stl_zip_path', 'downloads_unlocked_at', 'unlock_attempts', 'build_started_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ClickerStatus::class,
            'settings' => 'array',
            'downloads_unlocked_at' => 'datetime',
            'build_started_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function body(): BelongsTo
    {
        return $this->belongsTo(ClickerBody::class, 'clicker_body_id');
    }

    public function isUnlocked(): bool
    {
        return $this->downloads_unlocked_at !== null;
    }

    /** Folder on the private disk that holds every file of this design. */
    public function directory(): string
    {
        return "clickers/{$this->id}";
    }
}
```

Create `database/factories/ClickerBodyFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\ClickerBody;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ClickerBody> */
class ClickerBodyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'description' => null,
            'preview_image' => null,
            'model_files' => ['resources/clicker/bodies/placeholder-slab/body.stl'],
            'config' => ClickerBody::sampleConfig(),
            'active' => true,
        ];
    }
}
```

Create `database/factories/ClickerFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\ClickerStatus;
use App\Models\Clicker;
use App\Models\ClickerBody;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Clicker> */
class ClickerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'clicker_body_id' => ClickerBody::factory(),
            'name' => 'My clicker',
            'settings' => [
                'colors' => [
                    ['hex' => '#1D4ED8', 'height_mm' => 1, 'enabled' => true],
                    ['hex' => '#DC2626', 'height_mm' => 2, 'enabled' => true],
                ],
                'scale' => 1,
                'offset_x' => 0,
                'offset_y' => 0,
                'outline_mm' => 0,
                'outline_hex' => '#111111',
            ],
            'status' => ClickerStatus::Draft,
        ];
    }
}
```

- [ ] **Step 5: Add the ledger reasons, the credit service methods and the price**

In `app/Enums/LedgerReason.php`, add after `case Download = 'download';`:

```php
    case ClickerDownload = 'clicker_download';
    case ClickerRefund = 'clicker_refund';
```

In `app/Models/CreditLedgerEntry.php`, change `$fillable` to:

```php
    protected $fillable = ['user_id', 'delta', 'reason', 'creation_id', 'stylization_id', 'clicker_id', 'clicker_attempt'];
```

In `app/Services/Credits/CreditService.php`, add `use App\Models\Clicker;` with the other imports, and add these two methods after `spendForDownload()`:

```php
    /** Charge one numbered attempt to unlock a clicker's downloads. */
    public function spendForClicker(User $user, int $amount, Clicker $clicker, int $attempt): CreditLedgerEntry
    {
        return $this->charge($user, $amount, LedgerReason::ClickerDownload, ['clicker_id' => $clicker->id, 'clicker_attempt' => $attempt]);
    }

    /**
     * Give back one numbered attempt. Returns null when that attempt was never charged or was already
     * refunded, so a retry after a refund (attempt 2) is never confused with attempt 1.
     */
    public function refundClicker(Clicker $clicker, int $attempt): ?CreditLedgerEntry
    {
        $scope = fn (LedgerReason $reason) => CreditLedgerEntry::where('clicker_id', $clicker->id)
            ->where('clicker_attempt', $attempt)
            ->where('reason', $reason);

        $spent = $scope(LedgerReason::ClickerDownload)->first();

        if (! $spent) {
            return null;
        }

        try {
            return DB::transaction(function () use ($spent, $clicker, $attempt, $scope) {
                if ($scope(LedgerReason::ClickerRefund)->exists()) {
                    return null;
                }

                return CreditLedgerEntry::create([
                    'user_id' => $spent->user_id,
                    'delta' => -$spent->delta,
                    'reason' => LedgerReason::ClickerRefund,
                    'clicker_id' => $clicker->id,
                    'clicker_attempt' => $attempt,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent refund won the race; the unique index kept it to one.
            return null;
        }
    }
```

In `config/credits.php`, add inside the returned array (after `'download_cost'`):

```php

    // Charged once per clicker design to download its 3MF and STL files.
    'clicker_cost' => (int) env('CREDITS_CLICKER_COST', 50),
```

In `.env.example`, after the `CREDITS_DOWNLOAD_COST=100` line add:

```
CREDITS_CLICKER_COST=50
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan config:clear && vendor/bin/pest tests/Feature/ClickerModelsTest.php tests/Feature/ClickerCreditsTest.php`
Expected: PASS, 12 tests. Then run the whole suite: `vendor/bin/pest` and expect everything green.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint app/Enums/ClickerStatus.php app/Enums/LedgerReason.php app/Models/ClickerBody.php app/Models/Clicker.php app/Models/CreditLedgerEntry.php app/Services/Credits/CreditService.php config/credits.php database/factories/ClickerBodyFactory.php database/factories/ClickerFactory.php database/migrations/2026_10_06_000001_create_clicker_bodies_table.php database/migrations/2026_10_06_000002_create_clickers_table.php database/migrations/2026_10_06_000003_add_clicker_columns_to_credit_ledger_table.php tests/Feature/ClickerModelsTest.php tests/Feature/ClickerCreditsTest.php
git add app database config tests .env.example
git commit -m "feat: clicker tables, models and an attempt-numbered credit ledger"
```

Note for the dev database: `php artisan migrate` applies the three new migrations. Never run `migrate:fresh` against the dev database.

### Task 6: Configuration, the development body and its seeder

**Files:**
- Create: `config/clicker.php`, `database/seeders/ClickerBodySeeder.php`
- Create: `resources/clicker/tools/make-placeholder-body.mjs`, `resources/clicker/bodies/placeholder-slab/body.stl` (generated)
- Modify: `database/seeders/DatabaseSeeder.php`, `.env.example`
- Test: `tests/Feature/ClickerBodySeederTest.php`

**Interfaces:**
- Consumes: `ClickerBody::sampleConfig()` (Task 5), `capPolygon`, `extrudePolygons`, `encodeStl` (Part A).
- Produces: config keys `clicker.max_per_user` (20), `clicker.max_colors` (8), `clicker.upload.max_kb` (10240), `clicker.upload.min_edge` (256), `clicker.upload.max_edge` (4096), `clicker.node_binary`, `clicker.build_timeout_seconds` (90), `clicker.build_deadline_seconds` (300); seeder `ClickerBodySeeder` creating the body with slug `placeholder-slab`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ClickerBodySeederTest.php`:

```php
<?php

use App\Models\ClickerBody;
use Database\Seeders\ClickerBodySeeder;

it('seeds the development body once, with a complete config and a real model file', function () {
    $this->seed(ClickerBodySeeder::class);
    $this->seed(ClickerBodySeeder::class);

    $body = ClickerBody::where('slug', 'placeholder-slab')->firstOrFail();

    expect(ClickerBody::count())->toBe(1)
        ->and($body->active)->toBeTrue()
        ->and($body->config['cap'])->toHaveKeys(['shape', 'width', 'height', 'corner_radius', 'top_z', 'inset'])
        ->and($body->config['artwork_area'])->toHaveKeys(['cx', 'cy', 'width', 'height'])
        ->and($body->config)->toHaveKeys(['max_layer_height', 'min_feature']);

    foreach ($body->model_files as $file) {
        expect(base_path($file))->toBeFile();
    }
});

it('has sensible clicker limits in config', function () {
    expect(config('clicker.max_per_user'))->toBe(20)
        ->and(config('clicker.max_colors'))->toBe(8)
        ->and(config('clicker.upload.max_edge'))->toBe(4096)
        ->and(config('clicker.build_timeout_seconds'))->toBeLessThan(120);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Feature/ClickerBodySeederTest.php`
Expected: FAIL with `Class "Database\Seeders\ClickerBodySeeder" not found`.

- [ ] **Step 3: Write the config**

Create `config/clicker.php`:

```php
<?php

return [
    // Saved designs per user.
    'max_per_user' => (int) env('CLICKER_MAX_PER_USER', 20),

    // Colours in one design.
    'max_colors' => 8,

    // The editor uploads a PNG; the server re-encodes it within these limits.
    'upload' => [
        'max_kb' => 10240,
        'min_edge' => 256,
        'max_edge' => 4096,
        'max_pixels' => 40_000_000,
    ],

    // The Node binary that runs resources/clicker/build.mjs.
    'node_binary' => env('CLICKER_NODE_BINARY', 'node'),

    // The build process is killed after this long (must stay below the job's 120 s timeout).
    'build_timeout_seconds' => (int) env('CLICKER_BUILD_TIMEOUT', 90),

    // A build still "building" after this long (plus a grace period) is failed and refunded by the sweeper.
    'build_deadline_seconds' => 300,
];
```

In `.env.example`, append at the end:

```

CLICKER_MAX_PER_USER=20
CLICKER_NODE_BINARY=node
CLICKER_BUILD_TIMEOUT=90
```

- [ ] **Step 4: Write the placeholder body generator and run it**

Create `resources/clicker/tools/make-placeholder-body.mjs`:

```js
// Writes the development clicker body: a plain 40 x 50 x 6 mm rounded slab standing on z = 0.
// Run once with: node resources/clicker/tools/make-placeholder-body.mjs
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { capPolygon } from '../geometry.js';
import { extrudePolygons } from '../mesh.js';
import { encodeStl } from '../stl.js';

const cap = { shape: 'rounded', width: 40, height: 50, corner_radius: 6 };
const mesh = extrudePolygons([{ outer: capPolygon(cap), holes: [] }], 0, 6);
const target = fileURLToPath(new URL('../bodies/placeholder-slab/body.stl', import.meta.url));

mkdirSync(dirname(target), { recursive: true });
writeFileSync(target, encodeStl(mesh));
console.log(`wrote ${target}`);
```

Run: `node resources/clicker/tools/make-placeholder-body.mjs`
Expected: prints `wrote .../resources/clicker/bodies/placeholder-slab/body.stl`. The file is about 7 KB.

- [ ] **Step 5: Write the seeder**

Create `database/seeders/ClickerBodySeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\ClickerBody;
use Illuminate\Database\Seeder;

class ClickerBodySeeder extends Seeder
{
    public function run(): void
    {
        // A development stand-in. Replace it with the real, print-tested clicker bodies before launch:
        // drop each body's STL/3MF into resources/clicker/bodies/<slug>/ and add an entry like this one.
        ClickerBody::updateOrCreate(
            ['slug' => 'placeholder-slab'],
            [
                'name' => 'Placeholder slab (development only)',
                'description' => 'A plain rounded slab used until the real clicker bodies are added. It has no switch cavity.',
                'preview_image' => null,
                'model_files' => ['resources/clicker/bodies/placeholder-slab/body.stl'],
                'config' => ClickerBody::sampleConfig(),
                'active' => true,
            ],
        );
    }
}
```

In `database/seeders/DatabaseSeeder.php`, add after `$this->call(StyleSeeder::class);`:

```php
        $this->call(ClickerBodySeeder::class);
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/ClickerBodySeederTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 7: Seed the dev database, format and commit**

```bash
php artisan db:seed --class=ClickerBodySeeder --force
vendor/bin/pint config/clicker.php database/seeders/ClickerBodySeeder.php database/seeders/DatabaseSeeder.php tests/Feature/ClickerBodySeederTest.php
npx prettier --write resources/clicker/tools
git add config database resources/clicker tests .env.example
git commit -m "feat: clicker config and a development body with its seeder"
```

### Task 7: Safe artwork and thumbnail images

**Files:**
- Create: `app/Services/Clickers/ClickerImages.php`
- Modify: `tests/Pest.php` (add `fakePngBytes`)
- Test: `tests/Feature/ClickerImagesTest.php`

**Interfaces:**
- Consumes: `App\Exceptions\InvalidImageException` (exists), `config('clicker.upload.*')` (Task 6).
- Produces: `ClickerImages::artwork(string $path): string` (PNG bytes, transparency kept, longest side at most 4096 px) and `ClickerImages::thumbnail(string $path): string` (JPEG bytes, longest side at most 480 px, flattened onto white). Both throw `InvalidImageException` with a customer-safe message. Test helper `fakePngBytes(int $width = 400, int $height = 400, bool $transparent = true): string`.

- [ ] **Step 1: Add the test helper**

Append to `tests/Pest.php`:

```php

/** A real PNG. When $transparent, the top-left quarter is fully transparent and the rest is opaque red. */
function fakePngBytes(int $width = 400, int $height = 400, bool $transparent = true): string
{
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 220, 38, 38, 0));

    if ($transparent) {
        imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, intdiv($height, 2) - 1, imagecolorallocatealpha($image, 0, 0, 0, 127));
    }

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}
```

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/ClickerImagesTest.php`:

```php
<?php

use App\Exceptions\InvalidImageException;
use App\Services\Clickers\ClickerImages;

beforeEach(function () {
    $this->images = new ClickerImages;
    $this->file = function (string $bytes): string {
        $path = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($path, $bytes);

        return $path;
    };
});

it('re-encodes artwork as a PNG and keeps its transparency', function () {
    $png = $this->images->artwork(($this->file)(fakePngBytes(400, 400)));
    $image = imagecreatefromstring($png);

    expect(substr($png, 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        ->and((imagecolorat($image, 5, 5) >> 24) & 0x7F)->toBe(127, 'the top-left stays transparent')
        ->and((imagecolorat($image, 300, 300) >> 24) & 0x7F)->toBe(0, 'the rest stays opaque');
});

it('shrinks artwork wider than 4096 px', function () {
    $png = $this->images->artwork(($this->file)(fakePngBytes(5000, 300)));

    expect(getimagesizefromstring($png)[0])->toBe(4096)
        ->and(getimagesizefromstring($png)[1])->toBeLessThan(300);
});

it('rejects artwork that is too small', function () {
    expect(fn () => $this->images->artwork(($this->file)(fakePngBytes(100, 100))))
        ->toThrow(InvalidImageException::class, 'at least 256');
});

it('rejects a file that is not an image', function () {
    expect(fn () => $this->images->artwork(($this->file)('this is not an image')))
        ->toThrow(InvalidImageException::class, 'not a valid image');
    expect(fn () => $this->images->artwork('/no/such/file.png'))
        ->toThrow(InvalidImageException::class);
});

it('rejects an image over 40 megapixels without decoding it', function () {
    // A header that only declares 8000 x 8000 pixels: decoding it would need far more memory than the file is worth.
    $bomb = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', 8000, 8000)."\x08\x02\x00\x00\x00".pack('N', 0);

    expect(fn () => $this->images->artwork(($this->file)($bomb)))
        ->toThrow(InvalidImageException::class, '40 megapixels');
});

it('makes a small JPEG thumbnail', function () {
    $jpeg = $this->images->thumbnail(($this->file)(fakePngBytes(1000, 600)));
    $size = getimagesizefromstring($jpeg);

    expect(substr($jpeg, 0, 2))->toBe("\xFF\xD8")
        ->and($size[0])->toBe(480)
        ->and($size[1])->toBe(288);
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/ClickerImagesTest.php`
Expected: FAIL with `Class "App\Services\Clickers\ClickerImages" not found`.

- [ ] **Step 4: Write the implementation**

Create `app/Services/Clickers/ClickerImages.php`:

```php
<?php

namespace App\Services\Clickers;

use App\Exceptions\InvalidImageException;
use GdImage;

/** Re-encodes uploaded artwork and thumbnails so only clean, bounded raster images are ever stored. */
class ClickerImages
{
    public const THUMBNAIL_EDGE = 480;

    /**
     * Decode and re-encode artwork as a PNG: metadata stripped, transparency kept, longest side capped.
     *
     * @throws InvalidImageException
     */
    public function artwork(string $path): string
    {
        $source = $this->decode($path);
        $width = imagesx($source);
        $height = imagesy($source);
        $minEdge = (int) config('clicker.upload.min_edge');

        if (min($width, $height) < $minEdge) {
            throw new InvalidImageException("The artwork must be at least {$minEdge} pixels on each side.");
        }

        $scale = min(1, (int) config('clicker.upload.max_edge') / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagepng($canvas, null, 6);

        return (string) ob_get_clean();
    }

    /**
     * A small JPEG preview of the design, flattened onto white.
     *
     * @throws InvalidImageException
     */
    public function thumbnail(string $path): string
    {
        $source = $this->decode($path);
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, self::THUMBNAIL_EDGE / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagejpeg($canvas, null, 85);

        return (string) ob_get_clean();
    }

    /** @throws InvalidImageException */
    private function decode(string $path): GdImage
    {
        $size = is_file($path) ? @getimagesize($path) : false;

        if ($size === false) {
            throw new InvalidImageException('The file is not a valid image.');
        }

        // Decoding costs memory per pixel, so cap the total before decoding anything.
        if ($size[0] * $size[1] > (int) config('clicker.upload.max_pixels')) {
            throw new InvalidImageException('The image is too large. Use an image under 40 megapixels.');
        }

        $image = @imagecreatefromstring((string) file_get_contents($path));

        if (! $image instanceof GdImage) {
            throw new InvalidImageException('The file is not a valid image.');
        }

        return $image;
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/ClickerImagesTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint app/Services/Clickers/ClickerImages.php tests/Feature/ClickerImagesTest.php tests/Pest.php
git add app tests
git commit -m "feat: re-encode clicker artwork and thumbnails safely"
```

### Task 8: The clicker service, exceptions, builder contract and build job

**Files:**
- Create: `app/Exceptions/ClickerLimitReachedException.php`, `app/Exceptions/ClickerStateException.php`
- Create: `app/Services/Clickers/ClickerBuildException.php`, `app/Services/Clickers/ClickerBuilder.php`, `app/Services/Clickers/ClickerService.php`
- Create: `app/Jobs/BuildClickerFiles.php`
- Test: `tests/Feature/ClickerServiceTest.php`

**Interfaces:**
- Consumes: models and `CreditService::spendForClicker/refundClicker` (Task 5), `fakePngBytes` and `fakeJpegBytes` helpers.
- Produces: `ClickerService::create(User $user, ClickerBody $body, string $name, array $settings, string $artworkPng, ?string $thumbnailJpeg): Clicker`; `update(Clicker $clicker, ClickerBody $body, string $name, array $settings, ?string $artworkPng, ?string $thumbnailJpeg): Clicker`; `duplicate(User $user, Clicker $source): Clicker`; `unlock(User $user, Clicker $clicker): Clicker`; `markReady(int $id, string $threemfPath, string $stlZipPath): bool`; `markFailed(int $id, string $message): bool`; `destroy(Clicker $clicker): void`. Exceptions: `ClickerLimitReachedException`, `ClickerStateException` (both `RuntimeException`), `InsufficientCreditsException` (existing, from unlock). `ClickerBuilder::build(Clicker $clicker): array{threemf: string, stl_zip: string}` throwing `ClickerBuildException` (message is customer-safe). Job `BuildClickerFiles(int $clickerId)`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ClickerServiceTest.php`:

```php
<?php

use App\Enums\ClickerStatus;
use App\Enums\LedgerReason;
use App\Exceptions\ClickerLimitReachedException;
use App\Exceptions\ClickerStateException;
use App\Exceptions\InsufficientCreditsException;
use App\Jobs\BuildClickerFiles;
use App\Models\Clicker;
use App\Models\ClickerBody;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Services\Clickers\ClickerService;
use App\Services\Credits\CreditService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    config(['credits.clicker_cost' => 50, 'clicker.max_per_user' => 3]);

    $this->credits = app(CreditService::class);
    $this->service = app(ClickerService::class);
    $this->user = User::factory()->create();
    $this->credits->grant($this->user, 120, LedgerReason::Signup);
    $this->body = ClickerBody::factory()->create();
    $this->settings = Clicker::factory()->make()->settings;
    $this->make = fn () => $this->service->create($this->user, $this->body, 'Skull', $this->settings, fakePngBytes(), fakeJpegBytes(300, 300));
});

it('saves a draft with its artwork and thumbnail', function () {
    $clicker = ($this->make)();

    expect($clicker->status)->toBe(ClickerStatus::Draft)
        ->and($clicker->name)->toBe('Skull')
        ->and($clicker->settings['colors'])->toHaveCount(2)
        ->and($clicker->artwork_path)->toBe("clickers/{$clicker->id}/artwork.png")
        ->and($clicker->thumbnail_path)->toBe("clickers/{$clicker->id}/thumbnail.jpg");
    Storage::disk('local')->assertExists([$clicker->artwork_path, $clicker->thumbnail_path]);
});

it('stops at the saved design limit', function () {
    ($this->make)();
    ($this->make)();
    ($this->make)();

    expect(fn () => ($this->make)())->toThrow(ClickerLimitReachedException::class)
        ->and(Clicker::count())->toBe(3);
});

it('updates a draft and replaces the artwork only when new artwork is given', function () {
    $clicker = ($this->make)();
    $original = Storage::disk('local')->get($clicker->artwork_path);
    $other = ClickerBody::factory()->create();

    $updated = $this->service->update($clicker, $other, 'Renamed', $this->settings, null, null);
    expect($updated->name)->toBe('Renamed')
        ->and($updated->clicker_body_id)->toBe($other->id)
        ->and(Storage::disk('local')->get($updated->artwork_path))->toBe($original);

    $replacement = fakePngBytes(300, 300, false);
    $this->service->update($updated, $other, 'Renamed', $this->settings, $replacement, null);
    expect(Storage::disk('local')->get($clicker->fresh()->artwork_path))->toBe($replacement);
});

it('refuses to change a design once it has been unlocked', function () {
    $clicker = ($this->make)();
    $this->service->unlock($this->user, $clicker);

    expect(fn () => $this->service->update($clicker->fresh(), $this->body, 'x', $this->settings, null, null))
        ->toThrow(ClickerStateException::class, 'locked');
});

it('duplicates a design into a fresh draft with copies of its files', function () {
    $clicker = ($this->make)();
    $this->service->unlock($this->user, $clicker);

    $copy = $this->service->duplicate($this->user, $clicker->fresh());

    expect($copy->id)->not->toBe($clicker->id)
        ->and($copy->name)->toBe('Copy of Skull')
        ->and($copy->status)->toBe(ClickerStatus::Draft)
        ->and($copy->isUnlocked())->toBeFalse()
        ->and($copy->unlock_attempts)->toBe(0)
        ->and($copy->artwork_path)->toBe("clickers/{$copy->id}/artwork.png")
        ->and(Storage::disk('local')->get($copy->artwork_path))->toBe(Storage::disk('local')->get($clicker->artwork_path));
});

it('does not duplicate past the limit', function () {
    $clicker = ($this->make)();
    ($this->make)();
    ($this->make)();

    expect(fn () => $this->service->duplicate($this->user, $clicker))->toThrow(ClickerLimitReachedException::class);
});

it('charges once to unlock, starts a build, and ignores a second click', function () {
    $clicker = ($this->make)();

    $this->service->unlock($this->user, $clicker);
    $this->service->unlock($this->user, $clicker->fresh());

    $fresh = $clicker->fresh();
    expect($fresh->status)->toBe(ClickerStatus::Building)
        ->and($fresh->isUnlocked())->toBeTrue()
        ->and($fresh->unlock_attempts)->toBe(1)
        ->and($fresh->build_started_at)->not->toBeNull()
        ->and($this->credits->balance($this->user))->toBe(70)
        ->and(CreditLedgerEntry::where('reason', LedgerReason::ClickerDownload)->count())->toBe(1);
    Queue::assertPushed(BuildClickerFiles::class, 1);
});

it('keeps everything as it was when the balance is too low', function () {
    config(['credits.clicker_cost' => 500]);
    $clicker = ($this->make)();

    expect(fn () => $this->service->unlock($this->user, $clicker))->toThrow(InsufficientCreditsException::class);

    expect($clicker->fresh()->status)->toBe(ClickerStatus::Draft)
        ->and($clicker->fresh()->isUnlocked())->toBeFalse()
        ->and($clicker->fresh()->unlock_attempts)->toBe(0)
        ->and($this->credits->balance($this->user))->toBe(120);
    Queue::assertNothingPushed();
});

it('will not unlock a design without artwork', function () {
    $clicker = Clicker::factory()->create(['user_id' => $this->user->id]);

    expect(fn () => $this->service->unlock($this->user, $clicker))->toThrow(ClickerStateException::class, 'artwork');
    expect($this->credits->balance($this->user))->toBe(120);
});

it('marks a building design ready once, with its file paths', function () {
    $clicker = ($this->make)();
    $this->service->unlock($this->user, $clicker);

    expect($this->service->markReady($clicker->id, 'clickers/1/clicker.3mf', 'clickers/1/clicker-stl.zip'))->toBeTrue();

    $fresh = $clicker->fresh();
    expect($fresh->status)->toBe(ClickerStatus::Ready)
        ->and($fresh->threemf_path)->toBe('clickers/1/clicker.3mf')
        ->and($fresh->stl_zip_path)->toBe('clickers/1/clicker-stl.zip')
        ->and($this->service->markReady($clicker->id, 'a', 'b'))->toBeFalse();
});

it('fails a building design, refunds it exactly once and lets the user unlock again', function () {
    $clicker = ($this->make)();
    $this->service->unlock($this->user, $clicker);

    expect($this->service->markFailed($clicker->id, 'We could not build your files.'))->toBeTrue()
        ->and($this->service->markFailed($clicker->id, 'again'))->toBeFalse();

    $failed = $clicker->fresh();
    expect($failed->status)->toBe(ClickerStatus::Failed)
        ->and($failed->build_error)->toBe('We could not build your files.')
        ->and($failed->isUnlocked())->toBeFalse()
        ->and($this->credits->balance($this->user))->toBe(120);

    $this->service->unlock($this->user, $failed);

    $second = $clicker->fresh();
    expect($second->status)->toBe(ClickerStatus::Building)
        ->and($second->unlock_attempts)->toBe(2)
        ->and($second->build_error)->toBeNull()
        ->and($this->credits->balance($this->user))->toBe(70)
        ->and(CreditLedgerEntry::where('clicker_id', $clicker->id)->where('reason', LedgerReason::ClickerDownload)->pluck('clicker_attempt')->sort()->values()->all())->toBe([1, 2]);
    Queue::assertPushed(BuildClickerFiles::class, 2);
});

it('does not fail or refund a design that is already ready', function () {
    $clicker = ($this->make)();
    $this->service->unlock($this->user, $clicker);
    $this->service->markReady($clicker->id, 'a', 'b');

    expect($this->service->markFailed($clicker->id, 'late'))->toBeFalse()
        ->and($clicker->fresh()->status)->toBe(ClickerStatus::Ready)
        ->and($this->credits->balance($this->user))->toBe(70);
});

it('deletes a design with its files', function () {
    $clicker = ($this->make)();
    $path = $clicker->artwork_path;

    $this->service->destroy($clicker);

    expect(Clicker::count())->toBe(0);
    Storage::disk('local')->assertMissing($path);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan config:clear && vendor/bin/pest tests/Feature/ClickerServiceTest.php`
Expected: FAIL with `Class "App\Services\Clickers\ClickerService" not found`.

- [ ] **Step 3: Write the exceptions and the builder contract**

Create `app/Exceptions/ClickerLimitReachedException.php`:

```php
<?php

namespace App\Exceptions;

use RuntimeException;

class ClickerLimitReachedException extends RuntimeException {}
```

Create `app/Exceptions/ClickerStateException.php`:

```php
<?php

namespace App\Exceptions;

use RuntimeException;

/** The design is in a state that does not allow the requested action (locked, no artwork, building...). */
class ClickerStateException extends RuntimeException {}
```

Create `app/Services/Clickers/ClickerBuildException.php`:

```php
<?php

namespace App\Services\Clickers;

use RuntimeException;

/** A build failed. The message is shown to the customer, so it must be plain and safe. */
class ClickerBuildException extends RuntimeException {}
```

Create `app/Services/Clickers/ClickerBuilder.php`:

```php
<?php

namespace App\Services\Clickers;

use App\Models\Clicker;

interface ClickerBuilder
{
    /**
     * Build the print files for a design and store them on the private disk.
     *
     * @return array{threemf: string, stl_zip: string} storage paths of the 3MF and of the STL zip
     *
     * @throws ClickerBuildException
     */
    public function build(Clicker $clicker): array;
}
```

- [ ] **Step 4: Write the service**

Create `app/Services/Clickers/ClickerService.php`:

```php
<?php

namespace App\Services\Clickers;

use App\Enums\ClickerStatus;
use App\Exceptions\ClickerLimitReachedException;
use App\Exceptions\ClickerStateException;
use App\Exceptions\InsufficientCreditsException;
use App\Jobs\BuildClickerFiles;
use App\Models\Clicker;
use App\Models\ClickerBody;
use App\Models\User;
use App\Services\Credits\CreditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ClickerService
{
    public function __construct(private CreditService $credits) {}

    /**
     * @param  array<string, mixed>  $settings
     *
     * @throws ClickerLimitReachedException
     */
    public function create(User $user, ClickerBody $body, string $name, array $settings, string $artworkPng, ?string $thumbnailJpeg): Clicker
    {
        $clicker = DB::transaction(function () use ($user, $body, $name, $settings) {
            $this->assertRoom($user);

            return Clicker::create([
                'user_id' => $user->id,
                'clicker_body_id' => $body->id,
                'name' => $name,
                'settings' => $settings,
                'status' => ClickerStatus::Draft,
            ]);
        });

        try {
            $this->storeFiles($clicker, $artworkPng, $thumbnailJpeg);
        } catch (Throwable $e) {
            Storage::disk('local')->deleteDirectory($clicker->directory());
            $clicker->delete();

            throw $e;
        }

        return $clicker->fresh();
    }

    /**
     * @param  array<string, mixed>  $settings
     *
     * @throws ClickerStateException when the design is unlocked or building
     */
    public function update(Clicker $clicker, ClickerBody $body, string $name, array $settings, ?string $artworkPng, ?string $thumbnailJpeg): Clicker
    {
        DB::transaction(function () use ($clicker, $body, $name, $settings) {
            $locked = Clicker::whereKey($clicker->id)->lockForUpdate()->firstOrFail();

            if ($locked->isUnlocked() || $locked->status === ClickerStatus::Building) {
                throw new ClickerStateException('This design is locked. Duplicate it to make changes.');
            }

            $locked->update(['clicker_body_id' => $body->id, 'name' => $name, 'settings' => $settings]);
        });

        $this->storeFiles($clicker, $artworkPng, $thumbnailJpeg);

        return $clicker->fresh();
    }

    /** @throws ClickerLimitReachedException */
    public function duplicate(User $user, Clicker $source): Clicker
    {
        $copy = DB::transaction(function () use ($user, $source) {
            $this->assertRoom($user);

            return Clicker::create([
                'user_id' => $user->id,
                'clicker_body_id' => $source->clicker_body_id,
                'name' => Str::limit('Copy of '.$source->name, 60, ''),
                'settings' => $source->settings,
                'status' => ClickerStatus::Draft,
            ]);
        });

        $disk = Storage::disk('local');

        try {
            $updates = [];
            foreach (['artwork_path' => 'artwork.png', 'thumbnail_path' => 'thumbnail.jpg'] as $column => $file) {
                if ($source->{$column} && $disk->exists($source->{$column})) {
                    $target = "{$copy->directory()}/{$file}";
                    $disk->copy($source->{$column}, $target);
                    $updates[$column] = $target;
                }
            }
            if ($updates) {
                $copy->update($updates);
            }
        } catch (Throwable $e) {
            $disk->deleteDirectory($copy->directory());
            $copy->delete();

            throw $e;
        }

        return $copy->fresh();
    }

    /**
     * Pay once to build and download the print files. Idempotent: unlocking an already unlocked design
     * charges nothing. Locks the clicker row first, then the user row (inside the charge); the build is
     * dispatched only after the transaction commits, and the sweeper refunds one that never starts.
     *
     * @throws ClickerStateException
     * @throws InsufficientCreditsException
     */
    public function unlock(User $user, Clicker $clicker): Clicker
    {
        $dispatch = false;

        $result = DB::transaction(function () use ($user, $clicker, &$dispatch) {
            $locked = Clicker::whereKey($clicker->id)->lockForUpdate()->firstOrFail();

            if ($locked->isUnlocked()) {
                return $locked;
            }

            if (! $locked->artwork_path) {
                throw new ClickerStateException('Add artwork before unlocking the downloads.');
            }

            $attempt = $locked->unlock_attempts + 1;
            $this->credits->spendForClicker($user, (int) config('credits.clicker_cost'), $locked, $attempt);

            $locked->update([
                'unlock_attempts' => $attempt,
                'downloads_unlocked_at' => now(),
                'status' => ClickerStatus::Building,
                'build_started_at' => now(),
                'build_error' => null,
                'threemf_path' => null,
                'stl_zip_path' => null,
            ]);
            $dispatch = true;

            return $locked;
        });

        if ($dispatch) {
            BuildClickerFiles::dispatch($result->id);
        }

        return $result;
    }

    /** Building to ready. Returns false when the design is no longer building. */
    public function markReady(int $clickerId, string $threemfPath, string $stlZipPath): bool
    {
        return Clicker::whereKey($clickerId)
            ->where('status', ClickerStatus::Building->value)
            ->update([
                'status' => ClickerStatus::Ready->value,
                'threemf_path' => $threemfPath,
                'stl_zip_path' => $stlZipPath,
                'build_error' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * Building to failed, refunding the latest attempt in the same transaction, and clearing the unlock so
     * the user can pay for a new attempt. Returns false when the design is no longer building.
     */
    public function markFailed(int $clickerId, string $message): bool
    {
        return DB::transaction(function () use ($clickerId, $message) {
            $clicker = Clicker::whereKey($clickerId)->lockForUpdate()->first();

            if (! $clicker || $clicker->status !== ClickerStatus::Building) {
                return false;
            }

            $clicker->update([
                'status' => ClickerStatus::Failed,
                'build_error' => $message,
                'downloads_unlocked_at' => null,
            ]);
            $this->credits->refundClicker($clicker, $clicker->unlock_attempts);

            return true;
        });
    }

    public function destroy(Clicker $clicker): void
    {
        Storage::disk('local')->deleteDirectory($clicker->directory());
        $clicker->delete();
    }

    /** Lock the user row so two parallel saves cannot both slip under the limit. */
    private function assertRoom(User $user): void
    {
        User::query()->whereKey($user->id)->lockForUpdate()->first();

        if (Clicker::where('user_id', $user->id)->count() >= (int) config('clicker.max_per_user')) {
            throw new ClickerLimitReachedException;
        }
    }

    private function storeFiles(Clicker $clicker, ?string $artworkPng, ?string $thumbnailJpeg): void
    {
        $disk = Storage::disk('local');
        $updates = [];

        if ($artworkPng !== null) {
            $updates['artwork_path'] = "{$clicker->directory()}/artwork.png";
            $disk->put($updates['artwork_path'], $artworkPng);
        }

        if ($thumbnailJpeg !== null) {
            $updates['thumbnail_path'] = "{$clicker->directory()}/thumbnail.jpg";
            $disk->put($updates['thumbnail_path'], $thumbnailJpeg);
        }

        if ($updates) {
            $clicker->update($updates);
        }
    }
}
```

- [ ] **Step 5: Write the build job**

Create `app/Jobs/BuildClickerFiles.php`:

```php
<?php

namespace App\Jobs;

use App\Enums\ClickerStatus;
use App\Models\Clicker;
use App\Services\Clickers\ClickerBuilder;
use App\Services\Clickers\ClickerBuildException;
use App\Services\Clickers\ClickerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use Throwable;

class BuildClickerFiles implements ShouldQueue
{
    use Queueable;

    /**
     * Worker limit. It must stay below the overlap lock's expiry (200 s), which must stay below the
     * queue connection's retry_after (300 s), and above the build process's own timeout (90 s).
     */
    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public int $clickerId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->clickerId))->dontRelease()->expireAfter(200)];
    }

    public function handle(ClickerBuilder $builder, ClickerService $clickers): void
    {
        $clicker = Clicker::with('body')->find($this->clickerId);

        if (! $clicker || $clicker->status !== ClickerStatus::Building) {
            return;
        }

        try {
            $paths = $builder->build($clicker);
        } catch (ClickerBuildException $e) {
            $clickers->markFailed($this->clickerId, $e->getMessage());

            return;
        }

        // If the design stopped building meanwhile (for example the sweeper failed it), drop the outputs.
        if (! $clickers->markReady($this->clickerId, $paths['threemf'], $paths['stl_zip'])) {
            Storage::disk('local')->delete([$paths['threemf'], $paths['stl_zip']]);
        }
    }

    /** Called by the queue when the job crashes or times out. */
    public function failed(?Throwable $exception): void
    {
        app(ClickerService::class)->markFailed($this->clickerId, 'We could not build your files.');
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/ClickerServiceTest.php`
Expected: PASS, 13 tests.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint app/Exceptions/ClickerLimitReachedException.php app/Exceptions/ClickerStateException.php app/Services/Clickers app/Jobs/BuildClickerFiles.php tests/Feature/ClickerServiceTest.php
git add app tests
git commit -m "feat: clicker service with a numbered paid unlock, plus the build job and builder contract"
```

### Task 9: The Node builder, its binding, the job tests and the sweeper

**Files:**
- Create: `app/Services/Clickers/NodeClickerBuilder.php`
- Modify: `app/Providers/CreatorServiceProvider.php`, `app/Console/Commands/SweepStaleCreations.php`
- Test: `tests/Feature/NodeClickerBuilderTest.php`, `tests/Feature/BuildClickerFilesTest.php`, `tests/Feature/SweepStaleClickersTest.php`

**Interfaces:**
- Consumes: `ClickerBuilder`, `ClickerBuildException`, `ClickerService`, `BuildClickerFiles` (Task 8); `resources/clicker/build.mjs` and the placeholder body (Tasks 4 and 6).
- Produces: `NodeClickerBuilder` bound as the default `ClickerBuilder`; the rate limiter name `clicker` (30 requests per minute per user); `creations:sweep` also failing and refunding stale clicker builds.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/NodeClickerBuilderTest.php`:

```php
<?php

use App\Models\Clicker;
use App\Services\Clickers\ClickerBuildException;
use App\Services\Clickers\NodeClickerBuilder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->clicker = Clicker::factory()->create();
    $this->clicker->update(['artwork_path' => "clickers/{$this->clicker->id}/artwork.png"]);
    Storage::disk('local')->put($this->clicker->artwork_path, fakePngBytes());
    $this->builder = app(NodeClickerBuilder::class);
});

it('runs the node script on a job folder and moves the outputs into place', function () {
    $job = null;
    Process::fake(function ($process) use (&$job) {
        $folder = end($process->command);
        $job = json_decode(file_get_contents("{$folder}/job.json"), true);
        file_put_contents("{$folder}/clicker.3mf", '3mf');
        file_put_contents("{$folder}/clicker-stl.zip", 'zip');

        return Process::result();
    });

    $paths = $this->builder->build($this->clicker->load('body'));

    expect($paths)->toBe([
        'threemf' => "clickers/{$this->clicker->id}/clicker.3mf",
        'stl_zip' => "clickers/{$this->clicker->id}/clicker-stl.zip",
    ]);
    Storage::disk('local')->assertExists(array_values($paths));
    Storage::disk('local')->assertMissing("clickers/{$this->clicker->id}/job");
    Process::assertRan(fn ($process) => $process->command[0] === 'node'
        && in_array('--max-old-space-size=768', $process->command, true)
        && str_ends_with($process->command[2], 'build.mjs'));

    expect($job)->toHaveKeys(['settings', 'body', 'bodyFiles', 'title', 'folder'])
        ->and($job['body'])->toHaveKeys(['cap', 'artwork_area', 'max_layer_height', 'min_feature'])
        ->and($job['body'])->not->toHaveKey('switch')
        ->and($job['bodyFiles'][0]['path'])->toEndWith('body.stl')
        ->and($job['bodyFiles'][0]['path'])->toBeFile()
        ->and($job['folder'])->toBe("clicker-{$this->clicker->id}");
});

it('turns a failing script into a customer-safe error and cleans up', function () {
    Process::fake(['*' => Process::result('', 'BUILD FAILED: secret detail', 1)]);

    expect(fn () => $this->builder->build($this->clicker->load('body')))
        ->toThrow(ClickerBuildException::class, NodeClickerBuilder::MESSAGE);
    Storage::disk('local')->assertMissing("clickers/{$this->clicker->id}/job");
});

it('fails when the script reports success but writes no files', function () {
    Process::fake(['*' => Process::result()]);

    expect(fn () => $this->builder->build($this->clicker->load('body')))->toThrow(ClickerBuildException::class);
});

it('refuses a body file outside the bodies folder', function () {
    Log::spy();
    $this->clicker->body->update(['model_files' => ['composer.json']]);
    Process::fake();

    expect(fn () => $this->builder->build($this->clicker->load('body')))->toThrow(ClickerBuildException::class);
    Process::assertNothingRan();
    Log::shouldHaveReceived('critical')->once();
});

it('builds real print files with node', function () {
    $this->clicker->update(['settings' => [
        'colors' => [
            ['hex' => '#DC2626', 'height_mm' => 1, 'enabled' => true],
            ['hex' => '#1D4ED8', 'height_mm' => 2, 'enabled' => true],
        ],
        'scale' => 1, 'offset_x' => 0, 'offset_y' => 0, 'outline_mm' => 0, 'outline_hex' => '#111111',
    ]]);

    $paths = $this->builder->build($this->clicker->load('body'));

    expect(substr(Storage::disk('local')->get($paths['threemf']), 0, 2))->toBe('PK')
        ->and(substr(Storage::disk('local')->get($paths['stl_zip']), 0, 2))->toBe('PK');
})->skip(fn () => ! str_starts_with(trim((string) @shell_exec('node --version 2>&1')), 'v'), 'Node is not installed.');
```

Create `tests/Feature/BuildClickerFilesTest.php`:

```php
<?php

use App\Enums\ClickerStatus;
use App\Enums\LedgerReason;
use App\Jobs\BuildClickerFiles;
use App\Models\Clicker;
use App\Models\User;
use App\Services\Clickers\ClickerBuilder;
use App\Services\Clickers\ClickerBuildException;
use App\Services\Clickers\ClickerService;
use App\Services\Credits\CreditService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    config(['credits.clicker_cost' => 50]);

    $this->credits = app(CreditService::class);
    $this->service = app(ClickerService::class);
    $this->user = User::factory()->create();
    $this->credits->grant($this->user, 120, LedgerReason::Signup);

    $this->clicker = Clicker::factory()->create(['user_id' => $this->user->id]);
    $this->clicker->update(['artwork_path' => "clickers/{$this->clicker->id}/artwork.png"]);
    Storage::disk('local')->put($this->clicker->artwork_path, fakePngBytes());
    $this->service->unlock($this->user, $this->clicker);

    $this->run = fn () => (new BuildClickerFiles($this->clicker->id))->handle(app(ClickerBuilder::class), $this->service);
});

it('stores the files and marks the design ready', function () {
    app()->bind(ClickerBuilder::class, fn () => new class implements ClickerBuilder
    {
        public function build(Clicker $clicker): array
        {
            Storage::disk('local')->put("{$clicker->directory()}/clicker.3mf", '3mf');
            Storage::disk('local')->put("{$clicker->directory()}/clicker-stl.zip", 'zip');

            return ['threemf' => "{$clicker->directory()}/clicker.3mf", 'stl_zip' => "{$clicker->directory()}/clicker-stl.zip"];
        }
    });

    ($this->run)();

    $fresh = $this->clicker->fresh();
    expect($fresh->status)->toBe(ClickerStatus::Ready)
        ->and($fresh->threemf_path)->toBe("clickers/{$fresh->id}/clicker.3mf")
        ->and($this->credits->balance($this->user))->toBe(70);
});

it('fails the design and refunds when the build fails', function () {
    app()->bind(ClickerBuilder::class, fn () => new class implements ClickerBuilder
    {
        public function build(Clicker $clicker): array
        {
            throw new ClickerBuildException('We could not build your files.');
        }
    });

    ($this->run)();

    $fresh = $this->clicker->fresh();
    expect($fresh->status)->toBe(ClickerStatus::Failed)
        ->and($fresh->build_error)->toBe('We could not build your files.')
        ->and($fresh->isUnlocked())->toBeFalse()
        ->and($this->credits->balance($this->user))->toBe(120);
});

it('does nothing when the design is not building', function () {
    $this->clicker->update(['status' => ClickerStatus::Draft]);
    app()->bind(ClickerBuilder::class, fn () => new class implements ClickerBuilder
    {
        public function build(Clicker $clicker): array
        {
            throw new RuntimeException('must not be called');
        }
    });

    ($this->run)();

    expect($this->clicker->fresh()->status)->toBe(ClickerStatus::Draft)
        ->and($this->credits->balance($this->user))->toBe(70);
});

it('refunds when the queue reports the job as failed', function () {
    (new BuildClickerFiles($this->clicker->id))->failed(new RuntimeException('boom'));

    expect($this->clicker->fresh()->status)->toBe(ClickerStatus::Failed)
        ->and($this->credits->balance($this->user))->toBe(120);
});

it('uses an overlap lock that expires between the worker timeout and the queue retry window', function () {
    $job = new BuildClickerFiles(1);
    $lock = $job->middleware()[0];

    expect($lock->expiresAfter)->toBeGreaterThan($job->timeout)
        ->and($lock->expiresAfter)->toBeLessThan(300)
        ->and($job->timeout)->toBeGreaterThan((int) config('clicker.build_timeout_seconds'));
});
```

Create `tests/Feature/SweepStaleClickersTest.php`:

```php
<?php

use App\Enums\ClickerStatus;
use App\Enums\LedgerReason;
use App\Models\Clicker;
use App\Models\User;
use App\Services\Clickers\ClickerService;
use App\Services\Credits\CreditService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    config(['credits.clicker_cost' => 50, 'clicker.build_deadline_seconds' => 300]);

    $this->credits = app(CreditService::class);
    $this->user = User::factory()->create();
    $this->credits->grant($this->user, 120, LedgerReason::Signup);

    $this->building = function (int $startedSecondsAgo): Clicker {
        $clicker = Clicker::factory()->create(['user_id' => $this->user->id, 'artwork_path' => 'clickers/x/artwork.png']);
        app(ClickerService::class)->unlock($this->user, $clicker);
        $clicker->update(['build_started_at' => now()->subSeconds($startedSecondsAgo)]);

        return $clicker;
    };
});

it('fails and refunds a build stuck past its deadline, exactly once', function () {
    $stale = ($this->building)(5000);

    $this->artisan('creations:sweep')->assertSuccessful();
    $this->artisan('creations:sweep')->assertSuccessful();

    expect($stale->fresh()->status)->toBe(ClickerStatus::Failed)
        ->and($stale->fresh()->build_error)->toBe('The files timed out.')
        ->and($this->credits->balance($this->user))->toBe(120);
});

it('leaves a recent build alone', function () {
    $recent = ($this->building)(30);

    $this->artisan('creations:sweep')->assertSuccessful();

    expect($recent->fresh()->status)->toBe(ClickerStatus::Building)
        ->and($this->credits->balance($this->user))->toBe(70);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan config:clear && vendor/bin/pest tests/Feature/NodeClickerBuilderTest.php tests/Feature/BuildClickerFilesTest.php tests/Feature/SweepStaleClickersTest.php`
Expected: FAIL with `Target [App\Services\Clickers\ClickerBuilder] is not instantiable` and `Class "App\Services\Clickers\NodeClickerBuilder" not found`.

- [ ] **Step 3: Write the Node builder**

Create `app/Services/Clickers/NodeClickerBuilder.php`:

```php
<?php

namespace App\Services\Clickers;

use App\Models\Clicker;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Builds the print files by running resources/clicker/build.mjs in Node. */
class NodeClickerBuilder implements ClickerBuilder
{
    /** Shown to the customer on any failure; the real reason goes to the log. */
    public const MESSAGE = 'We could not build your files.';

    public function build(Clicker $clicker): array
    {
        $disk = Storage::disk('local');
        $directory = $clicker->directory();
        $job = "{$directory}/job";

        $disk->deleteDirectory($job);
        $disk->makeDirectory($job);

        try {
            $this->prepare($clicker, $job);
            $this->run($clicker, $disk->path($job));

            $paths = [];
            foreach (['threemf' => 'clicker.3mf', 'stl_zip' => 'clicker-stl.zip'] as $key => $file) {
                if (! $disk->exists("{$job}/{$file}")) {
                    Log::error('The clicker build wrote no output file.', ['clicker_id' => $clicker->id, 'missing' => $file]);

                    throw new ClickerBuildException(self::MESSAGE);
                }

                $disk->delete("{$directory}/{$file}");
                $disk->move("{$job}/{$file}", "{$directory}/{$file}");
                $paths[$key] = "{$directory}/{$file}";
            }

            return $paths;
        } finally {
            $disk->deleteDirectory($job);
        }
    }

    /** Write job.json and copy the artwork into the job folder. */
    private function prepare(Clicker $clicker, string $job): void
    {
        $disk = Storage::disk('local');
        $body = $clicker->body;
        $root = realpath(resource_path('clicker/bodies'));

        $bodyFiles = [];
        foreach ($body->model_files as $relative) {
            $absolute = realpath(base_path($relative));

            // Only files inside the bodies folder may ever be handed to the script.
            if ($root === false || $absolute === false || ! str_starts_with($absolute, $root.DIRECTORY_SEPARATOR)) {
                Log::critical('A clicker body file is missing or outside the bodies folder.', ['body_id' => $body->id, 'file' => $relative]);

                throw new ClickerBuildException(self::MESSAGE);
            }

            $bodyFiles[] = ['path' => $absolute, 'name' => 'Body'];
        }

        if (! $clicker->artwork_path || ! $disk->exists($clicker->artwork_path)) {
            Log::error('The clicker artwork file is missing.', ['clicker_id' => $clicker->id]);

            throw new ClickerBuildException(self::MESSAGE);
        }

        $disk->copy($clicker->artwork_path, "{$job}/artwork.png");
        $disk->put("{$job}/job.json", json_encode([
            'settings' => $clicker->settings,
            'body' => Arr::only($body->config, ['cap', 'artwork_area', 'max_layer_height', 'min_feature']),
            'bodyFiles' => $bodyFiles,
            'title' => Str::limit($clicker->name, 60, ''),
            'folder' => "clicker-{$clicker->id}",
        ], JSON_THROW_ON_ERROR));
    }

    private function run(Clicker $clicker, string $jobPath): void
    {
        try {
            $result = Process::path(base_path())
                ->timeout((int) config('clicker.build_timeout_seconds'))
                ->run([(string) config('clicker.node_binary'), '--max-old-space-size=768', resource_path('clicker/build.mjs'), $jobPath]);
        } catch (ProcessTimedOutException) {
            Log::error('The clicker build timed out.', ['clicker_id' => $clicker->id]);

            throw new ClickerBuildException(self::MESSAGE);
        }

        if ($result->failed()) {
            Log::error('The clicker build failed.', [
                'clicker_id' => $clicker->id,
                'exit_code' => $result->exitCode(),
                'error' => Str::limit(trim($result->errorOutput()), 300),
            ]);

            throw new ClickerBuildException(self::MESSAGE);
        }
    }
}
```

- [ ] **Step 4: Bind it and add the rate limiter**

In `app/Providers/CreatorServiceProvider.php`, add `use App\Services\Clickers\ClickerBuilder;` and `use App\Services\Clickers\NodeClickerBuilder;` with the other imports, add this line inside `register()` after the stylizer binding closes:

```php
        $this->app->bind(ClickerBuilder::class, NodeClickerBuilder::class);
```

and add inside `boot()` after the `generate` limiter:

```php
        RateLimiter::for('clicker', fn (Request $request) => Limit::perMinute(30)
            ->by($request->user()?->id ?: $request->ip()));
```

- [ ] **Step 5: Extend the sweeper**

In `app/Console/Commands/SweepStaleCreations.php`:

Add imports `use App\Enums\ClickerStatus;`, `use App\Models\Clicker;` and `use App\Services\Clickers\ClickerService;` (keep the list alphabetical).

Replace the `handle()` method with:

```php
    public function handle(CreationService $creations, StylizationService $stylizations, ClickerService $clickers): int
    {
        $failedCreations = $this->sweepCreations($creations);
        $failedPreviews = $this->sweepStylizations($stylizations);
        $failedClickers = $this->sweepClickers($clickers);

        $this->info("Failed {$failedCreations} stale creation(s), {$failedPreviews} stale preview(s) and {$failedClickers} stale clicker build(s).");

        return self::SUCCESS;
    }
```

Change the `$description` to `'Fail and refund creations, previews and clicker builds stuck past their timeout'`.

Add this method at the end of the class:

```php
    private function sweepClickers(ClickerService $clickers): int
    {
        $cutoff = now()->subSeconds((int) config('clicker.build_deadline_seconds') + 120);
        $failed = 0;

        Clicker::query()
            ->where('status', ClickerStatus::Building->value)
            ->where('build_started_at', '<', $cutoff)
            ->pluck('id')
            ->each(function (int $id) use ($clickers, &$failed) {
                if ($clickers->markFailed($id, 'The files timed out.')) {
                    $failed++;
                }
            });

        return $failed;
    }
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/NodeClickerBuilderTest.php tests/Feature/BuildClickerFilesTest.php tests/Feature/SweepStaleClickersTest.php`
Expected: PASS, 11 tests (the real-Node test is skipped, not failed, if Node is missing). Then run the full suite: `vendor/bin/pest`; the existing sweeper tests must still pass.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint app/Services/Clickers/NodeClickerBuilder.php app/Providers/CreatorServiceProvider.php app/Console/Commands/SweepStaleCreations.php tests/Feature/NodeClickerBuilderTest.php tests/Feature/BuildClickerFilesTest.php tests/Feature/SweepStaleClickersTest.php
git add app tests
git commit -m "feat: build clicker files with Node, and sweep stuck builds with a refund"
```

### Task 10: Policy, validation, resources, controllers and routes

**Files:**
- Create: `app/Policies/ClickerPolicy.php`, `app/Http/Requests/SaveClickerRequest.php`
- Create: `app/Http/Resources/ClickerResource.php`, `app/Http/Resources/ClickerBodyResource.php`
- Create: `app/Http/Controllers/ClickerController.php`, `app/Http/Controllers/ClickerFileController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ClickerHttpTest.php`, `tests/Feature/ClickerFilesTest.php`

**Interfaces:**
- Consumes: `ClickerService`, `ClickerImages`, `CreditService`, `Money::peso()`, the models and exceptions of Tasks 5 to 9.
- Produces (routes, all behind `auth`): `GET /clickers` (`clickers.index`, Inertia page `clickers/Index` with props `clickers[]`, `limit`), `GET /clickers/create` (`clickers.create`, page `clickers/Edit`), `POST /clickers` (`clickers.store`), `GET /clickers/{clicker}` (`clickers.show`, page `clickers/Edit`), `PUT /clickers/{clicker}` (`clickers.update`), `DELETE /clickers/{clicker}` (`clickers.destroy`), `POST /clickers/{clicker}/duplicate` (`clickers.duplicate`), `POST /clickers/{clicker}/unlock` (`clickers.unlock`), `GET /clickers/{clicker}/files/{type}` (`clickers.files`, type is `artwork`, `thumbnail`, `3mf` or `stl`).
- The `clickers/Edit` page props: `clicker` (a `ClickerResource` array or `null`), `bodies[]` (`ClickerBodyResource`), `balance` (int), `unlock_cost` (int), `max_colors` (int). A `ClickerResource` is `{id, name, status, error, settings, body_id, unlocked, editable, created_at, urls: {artwork, thumbnail, threemf, stl_zip}}`; `urls.threemf` and `urls.stl_zip` are `null` unless the design is unlocked and ready. A `ClickerBodyResource` is `{id, name, description, preview, config: {cap, artwork_area, max_layer_height, min_feature, switch}}`.
- Form fields for `POST /clickers` and `PUT /clickers/{clicker}`: `name`, `clicker_body_id`, `settings[colors][i][hex|height_mm|enabled]`, `settings[scale|offset_x|offset_y|outline_mm|outline_hex]`, `artwork` (PNG file, required on create), `thumbnail` (optional image file). Validation errors use those keys, plus `unlock` for unlock failures.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ClickerHttpTest.php`:

```php
<?php

use App\Enums\ClickerStatus;
use App\Enums\LedgerReason;
use App\Jobs\BuildClickerFiles;
use App\Models\Clicker;
use App\Models\ClickerBody;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Services\Credits\CreditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    config(['credits.clicker_cost' => 50, 'clicker.max_per_user' => 3]);

    $this->credits = app(CreditService::class);
    $this->user = User::factory()->create();
    $this->other = User::factory()->create();
    $this->credits->grant($this->user, 120, LedgerReason::Signup);
    $this->body = ClickerBody::factory()->create();
    $this->settings = Clicker::factory()->make()->settings;

    $this->payload = fn (array $over = []) => array_merge([
        'name' => 'Skull',
        'clicker_body_id' => $this->body->id,
        'settings' => $this->settings,
        'artwork' => UploadedFile::fake()->createWithContent('art.png', fakePngBytes()),
    ], $over);

    $this->saved = function (array $attrs = []): Clicker {
        $clicker = Clicker::factory()->create(['user_id' => $this->user->id, 'clicker_body_id' => $this->body->id] + $attrs);
        $clicker->update(['artwork_path' => "clickers/{$clicker->id}/artwork.png"]);
        Storage::disk('local')->put($clicker->artwork_path, fakePngBytes());

        return $clicker->fresh();
    };
});

it('sends guests to the login page', function () {
    $this->get('/clickers')->assertRedirect('/login');
    $this->get('/clickers/create')->assertRedirect('/login');
    $this->post('/clickers')->assertRedirect('/login');
});

it('lists only the current users designs', function () {
    ($this->saved)();
    Clicker::factory()->create(['user_id' => $this->other->id]);

    $this->actingAs($this->user)->get('/clickers')->assertInertia(fn (Assert $page) => $page
        ->component('clickers/Index')
        ->has('clickers', 1)
        ->where('limit', 3));
});

it('shows the editor with the active bodies but never their model files', function () {
    ClickerBody::factory()->create(['active' => false]);

    $this->actingAs($this->user)->get('/clickers/create')->assertInertia(fn (Assert $page) => $page
        ->component('clickers/Edit')
        ->where('clicker', null)
        ->has('bodies', 1)
        ->where('bodies.0.config.cap.shape', 'rounded')
        ->missing('bodies.0.model_files')
        ->missing('bodies.0.config.base_thickness')
        ->where('balance', 120)
        ->where('unlock_cost', 50)
        ->where('max_colors', 8));
});

it('saves a new design with typed settings and its files', function () {
    $this->actingAs($this->user)->post('/clickers', ($this->payload)([
        'settings' => array_replace($this->settings, ['scale' => '0.5', 'colors' => [
            ['hex' => '#1d4ed8', 'height_mm' => '1.5', 'enabled' => '1'],
            ['hex' => '#DC2626', 'height_mm' => '2', 'enabled' => '0'],
        ]]),
        'thumbnail' => UploadedFile::fake()->createWithContent('thumb.png', fakePngBytes(300, 300, false)),
    ]))->assertRedirect();

    $clicker = Clicker::firstOrFail();
    expect($clicker->user_id)->toBe($this->user->id)
        ->and($clicker->name)->toBe('Skull')
        ->and($clicker->status)->toBe(ClickerStatus::Draft)
        ->and($clicker->settings['scale'])->toBe(0.5)
        ->and($clicker->settings['colors'][0])->toBe(['hex' => '#1D4ED8', 'height_mm' => 1.5, 'enabled' => true])
        ->and($clicker->settings['colors'][1]['enabled'])->toBeFalse();
    Storage::disk('local')->assertExists([$clicker->artwork_path, $clicker->thumbnail_path]);
});

it('validates the form and creates nothing', function (string $field, array $override) {
    $this->actingAs($this->user)->post('/clickers', ($this->payload)($override))->assertSessionHasErrors($field);

    expect(Clicker::count())->toBe(0);
})->with([
    'no name' => ['name', ['name' => '']],
    'unknown body' => ['clicker_body_id', ['clicker_body_id' => 9999]],
    'bad hex' => ['settings.colors.0.hex', ['settings' => ['colors' => [['hex' => 'red', 'height_mm' => 1, 'enabled' => true]]] + ['scale' => 1, 'offset_x' => 0, 'offset_y' => 0, 'outline_mm' => 0, 'outline_hex' => '#111111']]],
    'no colours' => ['settings.colors', ['settings' => ['colors' => []] + ['scale' => 1, 'offset_x' => 0, 'offset_y' => 0, 'outline_mm' => 0, 'outline_hex' => '#111111']]],
    'no artwork' => ['artwork', ['artwork' => null]],
    'artwork is a jpeg' => ['artwork', ['artwork' => UploadedFile::fake()->image('art.jpg', 400, 400)]],
]);

it('rejects too many colours, absurd heights and out-of-range scale', function () {
    $colors = array_fill(0, 9, ['hex' => '#FFFFFF', 'height_mm' => 1, 'enabled' => true]);
    $base = ['scale' => 1, 'offset_x' => 0, 'offset_y' => 0, 'outline_mm' => 0, 'outline_hex' => '#111111'];

    $this->actingAs($this->user)->post('/clickers', ($this->payload)(['settings' => ['colors' => $colors] + $base]))->assertSessionHasErrors('settings.colors');
    $this->actingAs($this->user)->post('/clickers', ($this->payload)(['settings' => ['colors' => [['hex' => '#FFFFFF', 'height_mm' => 99, 'enabled' => true]]] + $base]))->assertSessionHasErrors('settings.colors.0.height_mm');
    $this->actingAs($this->user)->post('/clickers', ($this->payload)(['settings' => ['colors' => $this->settings['colors'], 'scale' => 5] + $base]))->assertSessionHasErrors('settings.scale');
});

it('rejects a PNG that cannot be decoded', function () {
    $this->actingAs($this->user)->post('/clickers', ($this->payload)([
        'artwork' => UploadedFile::fake()->createWithContent('art.png', truncatedPngBytes()),
    ]))->assertSessionHasErrors('artwork');

    expect(Clicker::count())->toBe(0);
});

it('stops at the saved design limit with a clear message', function () {
    foreach (range(1, 3) as $_) {
        ($this->saved)();
    }

    $this->actingAs($this->user)->post('/clickers', ($this->payload)())->assertSessionHasErrors(['name' => 'You have reached the limit of 3 saved clickers. Delete one to make room.']);
});

it('shows a design to its owner only', function () {
    $clicker = ($this->saved)();

    $this->actingAs($this->user)->get("/clickers/{$clicker->id}")->assertInertia(fn (Assert $page) => $page
        ->component('clickers/Edit')
        ->where('clicker.id', $clicker->id)
        ->where('clicker.editable', true)
        ->where('clicker.unlocked', false)
        ->where('clicker.urls.artwork', "/clickers/{$clicker->id}/files/artwork")
        ->where('clicker.urls.threemf', null)
        ->where('clicker.urls.stl_zip', null));

    $this->actingAs($this->other)->get("/clickers/{$clicker->id}")->assertForbidden();
});

it('updates a draft and refuses to change a locked design', function () {
    $clicker = ($this->saved)();

    $this->actingAs($this->user)->put("/clickers/{$clicker->id}", ($this->payload)(['name' => 'Renamed', 'artwork' => null]))->assertRedirect();
    expect($clicker->fresh()->name)->toBe('Renamed');

    $this->actingAs($this->other)->put("/clickers/{$clicker->id}", ($this->payload)(['artwork' => null]))->assertForbidden();

    $clicker->update(['downloads_unlocked_at' => now()]);
    $this->actingAs($this->user)->put("/clickers/{$clicker->id}", ($this->payload)(['name' => 'Nope', 'artwork' => null]))->assertForbidden();
    expect($clicker->fresh()->name)->toBe('Renamed');
});

it('duplicates a design for its owner', function () {
    $clicker = ($this->saved)();

    $this->actingAs($this->user)->post("/clickers/{$clicker->id}/duplicate")->assertRedirect();
    expect(Clicker::count())->toBe(2)->and(Clicker::latest('id')->first()->name)->toBe('Copy of My clicker');

    $this->actingAs($this->other)->post("/clickers/{$clicker->id}/duplicate")->assertForbidden();
});

it('deletes a design, but not while it is building or for someone else', function () {
    $clicker = ($this->saved)();

    $this->actingAs($this->other)->delete("/clickers/{$clicker->id}")->assertForbidden();

    $clicker->update(['status' => ClickerStatus::Building]);
    $this->actingAs($this->user)->delete("/clickers/{$clicker->id}")->assertForbidden();

    $clicker->update(['status' => ClickerStatus::Draft]);
    $this->actingAs($this->user)->delete("/clickers/{$clicker->id}")->assertRedirect('/clickers');
    expect(Clicker::count())->toBe(0);
    Storage::disk('local')->assertMissing("clickers/{$clicker->id}/artwork.png");
});

it('unlocks once, starts the build and charges a single time', function () {
    $clicker = ($this->saved)();

    $this->actingAs($this->user)->post("/clickers/{$clicker->id}/unlock")->assertRedirect();
    $this->actingAs($this->user)->post("/clickers/{$clicker->id}/unlock")->assertForbidden(); // already unlocked

    expect($clicker->fresh()->status)->toBe(ClickerStatus::Building)
        ->and($this->credits->balance($this->user))->toBe(70)
        ->and(CreditLedgerEntry::where('reason', LedgerReason::ClickerDownload)->count())->toBe(1);
    Queue::assertPushed(BuildClickerFiles::class, 1);
});

it('explains an unaffordable unlock and changes nothing', function () {
    $clicker = ($this->saved)();
    config(['credits.clicker_cost' => 500]);

    $this->actingAs($this->user)->post("/clickers/{$clicker->id}/unlock")
        ->assertSessionHasErrors(['unlock' => 'Not enough balance: unlocking downloads costs ₱500 and you have ₱120.']);

    expect($clicker->fresh()->status)->toBe(ClickerStatus::Draft)->and($this->credits->balance($this->user))->toBe(120);
    Queue::assertNothingPushed();
});

it('only lets the owner unlock', function () {
    $clicker = ($this->saved)();

    $this->actingAs($this->other)->post("/clickers/{$clicker->id}/unlock")->assertForbidden();
    expect($this->credits->balance($this->user))->toBe(120);
});

it('lets a failed design be unlocked again', function () {
    $clicker = ($this->saved)(['status' => ClickerStatus::Failed, 'unlock_attempts' => 1, 'build_error' => 'We could not build your files.']);
    $this->credits->spendForClicker($this->user, 50, $clicker, 1);
    $this->credits->refundClicker($clicker, 1);

    $this->actingAs($this->user)->post("/clickers/{$clicker->id}/unlock")->assertRedirect();

    expect($clicker->fresh()->unlock_attempts)->toBe(2)->and($this->credits->balance($this->user))->toBe(70);
});
```

Create `tests/Feature/ClickerFilesTest.php`:

```php
<?php

use App\Enums\ClickerStatus;
use App\Models\Clicker;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->owner = User::factory()->create();
    $this->other = User::factory()->create();
    $this->clicker = Clicker::factory()->create(['user_id' => $this->owner->id]);
    $dir = $this->clicker->directory();

    Storage::disk('local')->put("{$dir}/artwork.png", fakePngBytes());
    Storage::disk('local')->put("{$dir}/thumbnail.jpg", fakeJpegBytes(100, 100));
    Storage::disk('local')->put("{$dir}/clicker.3mf", 'three-mf');
    Storage::disk('local')->put("{$dir}/clicker-stl.zip", 'stl-zip');
    $this->clicker->update([
        'artwork_path' => "{$dir}/artwork.png",
        'thumbnail_path' => "{$dir}/thumbnail.jpg",
        'threemf_path' => "{$dir}/clicker.3mf",
        'stl_zip_path' => "{$dir}/clicker-stl.zip",
    ]);
});

it('serves the artwork and thumbnail to the owner with safe headers', function () {
    foreach (['artwork', 'thumbnail'] as $type) {
        $this->actingAs($this->owner)->get("/clickers/{$this->clicker->id}/files/{$type}")
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', 'sandbox')
            ->assertHeader('Cache-Control', 'no-cache, private');
    }
});

it('forbids everything to other users', function () {
    $this->clicker->update(['downloads_unlocked_at' => now(), 'status' => ClickerStatus::Ready]);

    foreach (['artwork', 'thumbnail', '3mf', 'stl'] as $type) {
        $this->actingAs($this->other)->get("/clickers/{$this->clicker->id}/files/{$type}")->assertForbidden();
    }
});

it('keeps the print files back until the design is unlocked and ready', function () {
    foreach (['3mf', 'stl'] as $type) {
        $this->actingAs($this->owner)->get("/clickers/{$this->clicker->id}/files/{$type}")->assertForbidden();
    }

    $this->clicker->update(['downloads_unlocked_at' => now(), 'status' => ClickerStatus::Building]);
    foreach (['3mf', 'stl'] as $type) {
        $this->actingAs($this->owner)->get("/clickers/{$this->clicker->id}/files/{$type}")->assertForbidden();
    }
});

it('serves the print files as named downloads once ready', function () {
    $this->clicker->update(['downloads_unlocked_at' => now(), 'status' => ClickerStatus::Ready]);
    $stamp = $this->clicker->created_at->format('Ymd-His');

    $this->actingAs($this->owner)->get("/clickers/{$this->clicker->id}/files/3mf")
        ->assertOk()
        ->assertHeader('content-disposition', "attachment; filename=clicker-{$this->clicker->id}-{$stamp}.3mf")
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs($this->owner)->get("/clickers/{$this->clicker->id}/files/stl")
        ->assertOk()
        ->assertHeader('content-disposition', "attachment; filename=clicker-{$this->clicker->id}-{$stamp}-stl.zip");
});

it('answers 404 for a missing file and for an unknown type', function () {
    $this->clicker->update(['thumbnail_path' => null]);

    $this->actingAs($this->owner)->get("/clickers/{$this->clicker->id}/files/thumbnail")->assertNotFound();
    $this->actingAs($this->owner)->get("/clickers/{$this->clicker->id}/files/secret")->assertNotFound();
});

it('exposes the download links only for an unlocked, ready design', function () {
    $this->clicker->update(['downloads_unlocked_at' => now(), 'status' => ClickerStatus::Ready]);

    $this->actingAs($this->owner)->get("/clickers/{$this->clicker->id}")->assertInertia(fn ($page) => $page
        ->where('clicker.urls.threemf', "/clickers/{$this->clicker->id}/files/3mf")
        ->where('clicker.urls.stl_zip', "/clickers/{$this->clicker->id}/files/stl")
        ->where('clicker.editable', false));
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan config:clear && vendor/bin/pest tests/Feature/ClickerHttpTest.php tests/Feature/ClickerFilesTest.php`
Expected: FAIL (404 on `/clickers`, since no routes exist yet).

- [ ] **Step 3: Write the policy**

Create `app/Policies/ClickerPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Enums\ClickerStatus;
use App\Models\Clicker;
use App\Models\User;

class ClickerPolicy
{
    public function view(User $user, Clicker $clicker): bool
    {
        return $clicker->user_id === $user->id;
    }

    /** A paid design is frozen, and so is one that is being built. Duplicate it to change it. */
    public function update(User $user, Clicker $clicker): bool
    {
        return $this->view($user, $clicker) && ! $clicker->isUnlocked() && $clicker->status !== ClickerStatus::Building;
    }

    /** A running build never loses its row. */
    public function delete(User $user, Clicker $clicker): bool
    {
        return $this->view($user, $clicker) && $clicker->status !== ClickerStatus::Building;
    }

    /** Only a design that has not been paid for (a draft, or one whose build failed and was refunded). */
    public function unlock(User $user, Clicker $clicker): bool
    {
        return $this->view($user, $clicker)
            && ! $clicker->isUnlocked()
            && in_array($clicker->status, [ClickerStatus::Draft, ClickerStatus::Failed], true);
    }
}
```

- [ ] **Step 4: Write the form request**

Create `app/Http/Requests/SaveClickerRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class SaveClickerRequest extends FormRequest
{
    /** Ownership is checked by the controller through the policy. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->route('clicker') === null;
        $hex = ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'];

        return [
            'name' => ['required', 'string', 'max:60'],
            'clicker_body_id' => ['required', 'integer', Rule::exists('clicker_bodies', 'id')->where('active', true)],
            'settings' => ['required', 'array'],
            'settings.colors' => ['required', 'array', 'min:1', 'max:'.config('clicker.max_colors')],
            'settings.colors.*.hex' => $hex,
            'settings.colors.*.height_mm' => ['required', 'numeric', 'min:0.1', 'max:5'],
            'settings.colors.*.enabled' => ['required', 'boolean'],
            'settings.scale' => ['required', 'numeric', 'min:0.1', 'max:1'],
            'settings.offset_x' => ['required', 'numeric', 'between:-50,50'],
            'settings.offset_y' => ['required', 'numeric', 'between:-50,50'],
            'settings.outline_mm' => ['required', 'numeric', 'min:0', 'max:2'],
            'settings.outline_hex' => $hex,
            'artwork' => [$creating ? 'required' : 'nullable', 'file', 'mimes:png', 'max:'.config('clicker.upload.max_kb'), $this->megapixelCap(...)],
            'thumbnail' => ['nullable', 'file', 'mimes:png,jpg,jpeg', 'max:1024'],
        ];
    }

    /** Decoding costs memory per pixel, so cap the total, not just each side. */
    private function megapixelCap(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->getRealPath()) {
            return;
        }

        $size = @getimagesize($value->getRealPath());

        if ($size !== false && $size[0] * $size[1] > (int) config('clicker.upload.max_pixels')) {
            $fail('The artwork is too large. Use an image under 40 megapixels.');
        }
    }

    /**
     * The validated settings with real types (multipart forms deliver everything as strings).
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $settings = $this->validated('settings');

        return [
            'colors' => array_values(array_map(fn (array $color) => [
                'hex' => strtoupper($color['hex']),
                'height_mm' => (float) $color['height_mm'],
                'enabled' => filter_var($color['enabled'], FILTER_VALIDATE_BOOLEAN),
            ], $settings['colors'])),
            'scale' => (float) $settings['scale'],
            'offset_x' => (float) $settings['offset_x'],
            'offset_y' => (float) $settings['offset_y'],
            'outline_mm' => (float) $settings['outline_mm'],
            'outline_hex' => strtoupper($settings['outline_hex']),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'artwork.mimes' => 'The artwork must be a PNG image.',
            'artwork.max' => 'The artwork must be 10 MB or smaller.',
            'artwork.required' => 'Add some artwork first.',
            'clicker_body_id.exists' => 'Choose one of the available clicker bodies.',
            'settings.colors.max' => 'A design can use at most '.config('clicker.max_colors').' colours.',
            'settings.colors.*.hex.regex' => 'Each colour must look like #1D4ED8.',
        ];
    }
}
```

- [ ] **Step 5: Write the resources**

Create `app/Http/Resources/ClickerBodyResource.php`:

```php
<?php

namespace App\Http\Resources;

use App\Models\ClickerBody;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/** @mixin ClickerBody */
class ClickerBodyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'preview' => $this->preview_image ? '/'.$this->preview_image : null,
            // The model files stay on the server; the browser only needs the numbers that drive the preview.
            'config' => Arr::only($this->config, ['cap', 'artwork_area', 'max_layer_height', 'min_feature', 'switch']),
        ];
    }
}
```

Create `app/Http/Resources/ClickerResource.php`:

```php
<?php

namespace App\Http\Resources;

use App\Enums\ClickerStatus;
use App\Models\Clicker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Clicker */
class ClickerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $file = fn (string $type) => route('clickers.files', ['clicker' => $this->id, 'type' => $type], absolute: false);
        $downloadable = $this->isUnlocked() && $this->status === ClickerStatus::Ready;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,
            'error' => $this->build_error,
            'settings' => $this->settings,
            'body_id' => $this->clicker_body_id,
            'unlocked' => $this->isUnlocked(),
            'editable' => ! $this->isUnlocked() && $this->status !== ClickerStatus::Building,
            'created_at' => $this->created_at->toIso8601String(),
            'urls' => [
                'artwork' => $this->artwork_path ? $file('artwork') : null,
                'thumbnail' => $this->thumbnail_path ? $file('thumbnail') : null,
                // The links only appear once the downloads are paid for and built; the file route enforces it too.
                'threemf' => $downloadable && $this->threemf_path ? $file('3mf') : null,
                'stl_zip' => $downloadable && $this->stl_zip_path ? $file('stl') : null,
            ],
        ];
    }
}
```

- [ ] **Step 6: Write the controllers**

Create `app/Http/Controllers/ClickerController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Exceptions\ClickerLimitReachedException;
use App\Exceptions\ClickerStateException;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\InvalidImageException;
use App\Http\Requests\SaveClickerRequest;
use App\Http\Resources\ClickerBodyResource;
use App\Http\Resources\ClickerResource;
use App\Models\Clicker;
use App\Models\ClickerBody;
use App\Services\Clickers\ClickerImages;
use App\Services\Clickers\ClickerService;
use App\Services\Credits\CreditService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ClickerController extends Controller
{
    public function index(Request $request): Response
    {
        $clickers = Clicker::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return Inertia::render('clickers/Index', [
            'clickers' => $clickers->map(fn (Clicker $c) => ClickerResource::make($c)->resolve())->values(),
            'limit' => (int) config('clicker.max_per_user'),
        ]);
    }

    public function create(Request $request, CreditService $credits): Response
    {
        return Inertia::render('clickers/Edit', $this->editorProps($request, null, $credits));
    }

    public function show(Request $request, Clicker $clicker, CreditService $credits): Response
    {
        Gate::authorize('view', $clicker);

        return Inertia::render('clickers/Edit', $this->editorProps($request, $clicker, $credits));
    }

    public function store(SaveClickerRequest $request, ClickerService $clickers, ClickerImages $images): RedirectResponse
    {
        $body = ClickerBody::active()->findOrFail($request->integer('clicker_body_id'));

        try {
            $clicker = $clickers->create(
                $request->user(),
                $body,
                $request->string('name')->toString(),
                $request->settings(),
                $images->artwork($request->file('artwork')->getRealPath()),
                $this->thumbnail($request, $images),
            );
        } catch (InvalidImageException $e) {
            throw ValidationException::withMessages(['artwork' => $e->getMessage()]);
        } catch (ClickerLimitReachedException) {
            throw ValidationException::withMessages(['name' => $this->limitMessage()]);
        }

        return redirect()->route('clickers.show', $clicker);
    }

    public function update(SaveClickerRequest $request, Clicker $clicker, ClickerService $clickers, ClickerImages $images): RedirectResponse
    {
        Gate::authorize('update', $clicker);

        $body = ClickerBody::active()->findOrFail($request->integer('clicker_body_id'));

        try {
            $clickers->update(
                $clicker,
                $body,
                $request->string('name')->toString(),
                $request->settings(),
                $request->hasFile('artwork') ? $images->artwork($request->file('artwork')->getRealPath()) : null,
                $this->thumbnail($request, $images),
            );
        } catch (InvalidImageException $e) {
            throw ValidationException::withMessages(['artwork' => $e->getMessage()]);
        } catch (ClickerStateException $e) {
            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }

        return back();
    }

    public function duplicate(Request $request, Clicker $clicker, ClickerService $clickers): RedirectResponse
    {
        Gate::authorize('view', $clicker);

        try {
            $copy = $clickers->duplicate($request->user(), $clicker);
        } catch (ClickerLimitReachedException) {
            throw ValidationException::withMessages(['name' => $this->limitMessage()]);
        }

        return redirect()->route('clickers.show', $copy);
    }

    public function destroy(Clicker $clicker, ClickerService $clickers): RedirectResponse
    {
        Gate::authorize('delete', $clicker);

        $clickers->destroy($clicker);

        return redirect()->route('clickers.index');
    }

    public function unlock(Request $request, Clicker $clicker, ClickerService $clickers): RedirectResponse
    {
        Gate::authorize('unlock', $clicker);

        try {
            $clickers->unlock($request->user(), $clicker);
        } catch (InsufficientCreditsException $e) {
            throw ValidationException::withMessages([
                'unlock' => 'Not enough balance: unlocking downloads costs '.Money::peso($e->required).' and you have '.Money::peso($e->balance).'.',
            ]);
        } catch (ClickerStateException $e) {
            throw ValidationException::withMessages(['unlock' => $e->getMessage()]);
        }

        return back();
    }

    /** The thumbnail is a nicety: a bad one is dropped instead of failing the save. */
    private function thumbnail(SaveClickerRequest $request, ClickerImages $images): ?string
    {
        if (! $request->hasFile('thumbnail')) {
            return null;
        }

        try {
            return $images->thumbnail($request->file('thumbnail')->getRealPath());
        } catch (InvalidImageException) {
            return null;
        }
    }

    private function limitMessage(): string
    {
        return 'You have reached the limit of '.config('clicker.max_per_user').' saved clickers. Delete one to make room.';
    }

    /** @return array<string, mixed> */
    private function editorProps(Request $request, ?Clicker $clicker, CreditService $credits): array
    {
        return [
            'clicker' => $clicker ? ClickerResource::make($clicker)->resolve() : null,
            'bodies' => ClickerBody::active()->orderBy('id')->get()
                ->map(fn (ClickerBody $b) => ClickerBodyResource::make($b)->resolve())->values(),
            'balance' => $credits->balance($request->user()),
            'unlock_cost' => (int) config('credits.clicker_cost'),
            'max_colors' => (int) config('clicker.max_colors'),
        ];
    }
}
```

Create `app/Http/Controllers/ClickerFileController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\ClickerStatus;
use App\Models\Clicker;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClickerFileController extends Controller
{
    public function __invoke(Clicker $clicker, string $type): StreamedResponse
    {
        Gate::authorize('view', $clicker);

        $path = match ($type) {
            'artwork' => $clicker->artwork_path,
            'thumbnail' => $clicker->thumbnail_path,
            '3mf' => $clicker->threemf_path,
            'stl' => $clicker->stl_zip_path,
        };

        // The two print files are the paid download.
        $isDownload = in_array($type, ['3mf', 'stl'], true);
        abort_if($isDownload && (! $clicker->isUnlocked() || $clicker->status !== ClickerStatus::Ready), 403, 'Unlock the downloads for this design first.');

        $disk = Storage::disk('local');
        abort_if(! $path || ! $disk->exists($path), 404);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            'Cache-Control' => 'private, no-cache',
        ];

        if ($isDownload) {
            $name = "clicker-{$clicker->id}-{$clicker->created_at->format('Ymd-His')}";

            return $disk->download($path, $type === '3mf' ? "{$name}.3mf" : "{$name}-stl.zip", $headers);
        }

        return $disk->response($path, null, $headers);
    }
}
```

- [ ] **Step 7: Add the routes**

In `routes/web.php`, add these imports with the other controller imports:

```php
use App\Http\Controllers\ClickerController;
use App\Http\Controllers\ClickerFileController;
```

Inside the authenticated group, immediately before the line `Route::post('stylizations', [StylizationController::class, 'store'])->middleware('throttle:generate')->name('stylizations.store');`, add:

```php
    Route::get('clickers', [ClickerController::class, 'index'])->name('clickers.index');
    Route::get('clickers/create', [ClickerController::class, 'create'])->name('clickers.create');
    Route::post('clickers', [ClickerController::class, 'store'])->middleware('throttle:clicker')->name('clickers.store');
    Route::get('clickers/{clicker}', [ClickerController::class, 'show'])->name('clickers.show');
    Route::put('clickers/{clicker}', [ClickerController::class, 'update'])->middleware('throttle:clicker')->name('clickers.update');
    Route::delete('clickers/{clicker}', [ClickerController::class, 'destroy'])->name('clickers.destroy');
    Route::post('clickers/{clicker}/duplicate', [ClickerController::class, 'duplicate'])->middleware('throttle:clicker')->name('clickers.duplicate');
    Route::post('clickers/{clicker}/unlock', [ClickerController::class, 'unlock'])->middleware('throttle:clicker')->name('clickers.unlock');
    Route::get('clickers/{clicker}/files/{type}', ClickerFileController::class)
        ->whereIn('type', ['artwork', 'thumbnail', '3mf', 'stl'])
        ->name('clickers.files');

```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/ClickerHttpTest.php tests/Feature/ClickerFilesTest.php`
Expected: PASS. If a test about `assertForbidden()` after a double unlock fails because the second POST should instead be a no-op, note that the policy intentionally forbids unlocking an already unlocked design (the UI hides the button); keep the test as written.
Then run the whole suite: `vendor/bin/pest` and expect everything green.

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint app/Policies/ClickerPolicy.php app/Http/Requests/SaveClickerRequest.php app/Http/Resources/ClickerResource.php app/Http/Resources/ClickerBodyResource.php app/Http/Controllers/ClickerController.php app/Http/Controllers/ClickerFileController.php routes/web.php tests/Feature/ClickerHttpTest.php tests/Feature/ClickerFilesTest.php
git add app routes tests
git commit -m "feat: clicker pages, saving, paid unlock and gated downloads over HTTP"
```

---

## Part C: Frontend (Vue + TypeScript)

All commands run from the repository root. After each task run `npx vue-tsc --noEmit` and `npx eslint <the files you changed>`. The dev stack is `composer dev` (or the three processes started by hand: `php artisan serve --port=8000`, `php artisan queue:listen --tries=1 --timeout=0`, `npm run dev`). **Restart the queue worker whenever `.env` or config changes**: a running worker keeps the settings it started with.

Browser checks in this part need a logged-in session. Create a throwaway local user for it (for example `clicker-test@example.test` with a generated password through `php artisan tinker`), give it a balance with `app(App\Services\Credits\CreditService::class)->grant($user, 200, App\Enums\LedgerReason::Topup)`, and delete the user when you are done. Never use the owner's real account.

### Task 11: Plumbing: path alias, typed wrapper, artwork loader, viewer parts mode, navigation

**Files:**
- Modify: `vite.config.ts`, `tsconfig.json`, `resources/js/components/ModelViewer.vue`, `resources/js/components/AppSidebar.vue`, `resources/js/pages/Credits.vue`
- Create: `resources/js/lib/clicker.ts`, `resources/js/lib/artwork.ts`

**Interfaces:**
- Consumes: `resources/clicker/geometry.js` exports (Part A).
- Produces: from `@/lib/clicker`: types `ClickerColor`, `ClickerSettings`, `BodyConfig`, `Pixels`, `Part`, `Analysis`; functions `analyze(image: Pixels, settings: ClickerSettings, body: BodyConfig): Analysis`, `extrude(analysis: Analysis, settings: ClickerSettings, body: BodyConfig): Part[]`, `bodyProxy(cap: BodyConfig['cap']): Part`, `paletteFor(image: Pixels, count: number): string[]`. From `@/lib/artwork`: `loadArtwork(file: File): Promise<{pixels: Pixels; png: Blob}>` and `loadArtworkFromUrl(url: string): Promise<{pixels: Pixels; png: Blob}>`. `ModelViewer` gets an optional `parts?: Part[]` prop (instead of `src`) and exposes `snapshot(): Promise<Blob | null>`; `src` becomes optional.

- [ ] **Step 1: Add the `@clicker` alias**

In `vite.config.ts`, change the `alias` block to:

```ts
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
            '@clicker': path.resolve(__dirname, './resources/clicker'),
        },
```

In `tsconfig.json`, change the `paths` entry to:

```json
        "paths": {
            "@/*": ["./resources/js/*"],
            "@clicker/*": ["./resources/clicker/*"],
            "ziggy-js": ["./vendor/tightenco/ziggy"]
        },
```

(Keep the existing comment text inside `paths` if you like; only the entries matter.)

- [ ] **Step 2: Write the typed wrapper**

Create `resources/js/lib/clicker.ts`:

```ts
import { analyzeArtwork, bodyProxyPart, buildParts, suggestPalette } from '@clicker/geometry.js';

/** Typed access to the shared clicker geometry module (plain JavaScript, also run by the server build). */

export type ClickerColor = { hex: string; height_mm: number; enabled: boolean };

export type ClickerSettings = {
    colors: ClickerColor[];
    scale: number;
    offset_x: number;
    offset_y: number;
    outline_mm: number;
    outline_hex: string;
};

export type BodyConfig = {
    cap: { shape: 'rect' | 'rounded' | 'circle'; width: number; height: number; corner_radius: number; top_z: number; inset: number };
    artwork_area: { cx: number; cy: number; width: number; height: number };
    max_layer_height: number;
    min_feature: number;
    switch?: string;
};

/** RGBA pixels, as from canvas getImageData(). */
export type Pixels = { data: Uint8ClampedArray; width: number; height: number };

export type Part = { kind: 'colour' | 'outline' | 'body'; name: string; hex: string; positions: Float32Array; indices: Uint32Array };

/** The slow half of the pipeline. Opaque to the editor except for the print notes. */
export type Analysis = { notes: string[] };

export const analyze = (image: Pixels, settings: ClickerSettings, body: BodyConfig): Analysis =>
    analyzeArtwork(image, settings, body, 'preview') as unknown as Analysis;

export const extrude = (analysis: Analysis, settings: ClickerSettings, body: BodyConfig): Part[] =>
    buildParts(analysis as never, settings, body) as Part[];

export const bodyProxy = (cap: BodyConfig['cap']): Part => bodyProxyPart(cap) as Part;

export const paletteFor = (image: Pixels, count: number): string[] => suggestPalette(image, count) as string[];
```

- [ ] **Step 3: Write the artwork loader**

Create `resources/js/lib/artwork.ts`:

```ts
import type { Pixels } from '@/lib/clicker';

const MAX_SIDE = 2048;
const MIN_SIDE = 256;

export type LoadedArtwork = { pixels: Pixels; png: Blob };

function decode(url: string): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = () => reject(new Error('That file could not be read as an image.'));
        image.src = url;
    });
}

/**
 * Draw any image the browser can show (PNG, JPG, SVG) onto a canvas of at most 2048 px, keeping
 * transparency. Returns the decoded pixels for the live preview and a PNG to upload. SVG is rasterised
 * here, so the server never has to parse it.
 */
export async function loadArtwork(file: File): Promise<LoadedArtwork> {
    const url = URL.createObjectURL(file);

    try {
        const image = await decode(url);
        const isSvg = file.type === 'image/svg+xml' || /\.svg$/i.test(file.name);
        const naturalWidth = image.naturalWidth || 1024;
        const naturalHeight = image.naturalHeight || 1024;

        // Vector art is drawn as large as allowed; photos keep their own size (shrunk if over the cap).
        const target = isSvg ? MAX_SIDE : Math.min(MAX_SIDE, Math.max(naturalWidth, naturalHeight));
        const scale = target / Math.max(naturalWidth, naturalHeight);
        const width = Math.max(1, Math.round(naturalWidth * scale));
        const height = Math.max(1, Math.round(naturalHeight * scale));

        if (Math.min(width, height) < MIN_SIDE) {
            throw new Error(`The artwork must be at least ${MIN_SIDE} pixels on each side.`);
        }

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const context = canvas.getContext('2d', { willReadFrequently: true });
        if (!context) throw new Error('Your browser cannot read images.');

        context.drawImage(image, 0, 0, width, height);
        const { data } = context.getImageData(0, 0, width, height);
        const png = await new Promise<Blob>((resolve, reject) => {
            canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('The artwork could not be converted.'))), 'image/png');
        });

        return { pixels: { data, width, height }, png };
    } finally {
        URL.revokeObjectURL(url);
    }
}

/** Load artwork the server already stores (the saved design's PNG). */
export async function loadArtworkFromUrl(url: string): Promise<LoadedArtwork> {
    const response = await fetch(url, { credentials: 'same-origin' });
    if (!response.ok) throw new Error('The saved artwork could not be loaded.');
    const blob = await response.blob();

    return loadArtwork(new File([blob], 'artwork.png', { type: blob.type || 'image/png' }));
}
```

- [ ] **Step 4: Teach the viewer to show in-memory parts**

Replace the whole content of `resources/js/components/ModelViewer.vue` with:

```vue
<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import * as THREE from 'three';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import type { Part } from '@/lib/clicker';

// Either `src` (a GLB to download) or `parts` (meshes built in the browser, millimetres, Z up).
const props = withDefaults(defineProps<{ src?: string; parts?: Part[]; autoRotate?: boolean; defaultLook?: 'textured' | 'clay' }>(), {
    autoRotate: true,
    defaultLook: 'clay',
});

const container = ref<HTMLDivElement | null>(null);
const state = ref<'loading' | 'ready' | 'error'>('loading');
const rotating = ref(props.autoRotate);
const preset = ref<'studio' | 'soft'>('soft');
const look = ref<'textured' | 'clay'>(props.defaultLook);

let renderer: THREE.WebGLRenderer | null = null;
let scene: THREE.Scene | null = null;
let camera: THREE.PerspectiveCamera | null = null;
let controls: OrbitControls | null = null;
let model: THREE.Object3D | null = null;
let frame = 0;
let loadId = 0;
let resizeObserver: ResizeObserver | null = null;
let lights: THREE.Light[] = [];
// Parts mode re-builds the model on every edit; the camera is framed once so the view does not jump.
let framedOnce = false;
const centerOffset = new THREE.Vector3();
// One plain matte material shared by every mesh in clay view; the painted ones are kept on the mesh.
const clayMaterial = new THREE.MeshStandardMaterial({ color: 0x6e6e6e, roughness: 0.92, metalness: 0 });

function makeDirectional(color: number, intensity: number, x: number, y: number, z: number) {
    const light = new THREE.DirectionalLight(color, intensity);
    light.position.set(x, y, z);
    return light;
}

function applyPreset() {
    if (!scene) return;
    lights.forEach((l) => scene!.remove(l));
    lights = preset.value === 'studio'
        ? [new THREE.HemisphereLight(0xffffff, 0x666677, 1.1), makeDirectional(0xffffff, 2.2, 3, 5, 4)]
        : [new THREE.HemisphereLight(0xffffff, 0xddddee, 2.2), makeDirectional(0xfff2e0, 0.6, -2, 3, 2)];
    lights.forEach((l) => scene!.add(l));
}

function applyLook() {
    model?.traverse((node) => {
        const mesh = node as THREE.Mesh;
        if (!mesh.isMesh) return;
        mesh.userData.original ??= mesh.material;
        mesh.material = look.value === 'clay' ? clayMaterial : mesh.userData.original;
    });
}

function resize() {
    if (!container.value || !renderer || !camera) return;
    const { clientWidth: w, clientHeight: h } = container.value;
    if (w === 0 || h === 0) return;
    renderer.setSize(w, h);
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
}

function frameModel(object: THREE.Object3D) {
    const box = new THREE.Box3().setFromObject(object);
    const size = box.getSize(new THREE.Vector3());
    const center = box.getCenter(new THREE.Vector3());
    object.position.sub(center);

    const maxDim = Math.max(size.x, size.y, size.z) || 1;
    camera!.near = maxDim / 100;
    camera!.far = maxDim * 100;
    camera!.position.set(0, maxDim * 0.3, maxDim * 2.2);
    camera!.updateProjectionMatrix();
    controls!.target.set(0, 0, 0);
    controls!.update();
}

function disposeModel() {
    if (!model || !scene) return;
    scene.remove(model);
    model.traverse((node) => {
        const mesh = node as THREE.Mesh;
        if (!mesh.isMesh) return;
        mesh.geometry.dispose();
        // Dispose the model's own materials, not the shared clay one (released on unmount).
        const own = mesh.userData.original ?? mesh.material;
        (Array.isArray(own) ? own : [own]).forEach((m: THREE.Material) => {
            for (const value of Object.values(m)) {
                if (value instanceof THREE.Texture) value.dispose();
            }
            m.dispose();
        });
    });
    model = null;
}

function load(url: string) {
    if (!scene) return;
    const id = ++loadId;
    state.value = 'loading';
    disposeModel();
    new GLTFLoader().load(
        url,
        (gltf) => {
            if (id !== loadId || !scene) return;
            model = gltf.scene;
            scene.add(model);
            applyLook();
            frameModel(model);
            state.value = 'ready';
        },
        undefined,
        () => {
            if (id !== loadId) return;
            state.value = 'error';
        },
    );
}

function showParts(list: Part[]) {
    if (!scene) return;
    loadId++; // cancels any download still in flight
    disposeModel();

    const group = new THREE.Group();
    for (const part of list) {
        const geometry = new THREE.BufferGeometry();
        geometry.setAttribute('position', new THREE.BufferAttribute(part.positions, 3));
        geometry.setIndex(new THREE.BufferAttribute(part.indices, 1));
        geometry.computeVertexNormals();
        group.add(new THREE.Mesh(geometry, new THREE.MeshStandardMaterial({ color: part.hex, roughness: 0.65, metalness: 0 })));
    }
    group.rotation.x = -Math.PI / 2; // parts are built with Z up; the scene is Y up

    model = group;
    scene.add(group);
    applyLook();

    if (framedOnce) {
        group.position.copy(centerOffset);
    } else {
        frameModel(group);
        centerOffset.copy(group.position);
        framedOnce = true;
    }
    state.value = 'ready';
}

/** A small PNG (at most 480 px) of what the viewer shows right now, or null when nothing can be drawn. */
function snapshot(): Promise<Blob | null> {
    return new Promise((resolve) => {
        if (!renderer || !scene || !camera) return resolve(null);
        renderer.render(scene, camera); // render and copy in the same task so the buffer is still filled

        const source = renderer.domElement;
        const scale = Math.min(1, 480 / Math.max(source.width, source.height));
        const copy = document.createElement('canvas');
        copy.width = Math.max(1, Math.round(source.width * scale));
        copy.height = Math.max(1, Math.round(source.height * scale));
        copy.getContext('2d')?.drawImage(source, 0, 0, copy.width, copy.height);
        copy.toBlob((blob) => resolve(blob), 'image/png');
    });
}

defineExpose({ snapshot });

function tick() {
    frame = requestAnimationFrame(tick);
    controls?.update();
    if (renderer && scene && camera) renderer.render(scene, camera);
}

onMounted(() => {
    if (!container.value) return;

    try {
        renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    } catch {
        // No WebGL (blocked, unsupported, or out of contexts): show the error overlay.
        renderer = null;
        state.value = 'error';
        return;
    }
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    container.value.appendChild(renderer.domElement);

    scene = new THREE.Scene();
    camera = new THREE.PerspectiveCamera(40, 1, 0.01, 100);
    controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.autoRotate = rotating.value;
    controls.autoRotateSpeed = 1.5;

    applyPreset();
    resize();
    resizeObserver = new ResizeObserver(resize);
    resizeObserver.observe(container.value);
    if (props.parts) showParts(props.parts);
    else if (props.src) load(props.src);
    tick();
});

watch(() => props.src, (url) => {
    if (url) load(url);
});
watch(() => props.parts, (list) => {
    if (list) showParts(list);
});
watch(preset, applyPreset);
watch(look, applyLook);
watch(rotating, (value) => {
    if (controls) controls.autoRotate = value;
});

onBeforeUnmount(() => {
    loadId++;
    cancelAnimationFrame(frame);
    resizeObserver?.disconnect();
    disposeModel();
    clayMaterial.dispose();
    controls?.dispose();
    renderer?.dispose();
    renderer?.forceContextLoss();
    renderer?.domElement.remove();
    renderer = scene = camera = controls = null;
});
</script>

<template>
    <div class="relative aspect-square w-full overflow-hidden rounded-xl border bg-muted/40">
        <div ref="container" class="absolute inset-0" />

        <div v-if="state === 'loading'" class="absolute inset-0 flex items-center justify-center text-sm text-muted-foreground">
            Loading model…
        </div>
        <div v-else-if="state === 'error'" class="absolute inset-0 flex items-center justify-center px-6 text-center text-sm text-destructive">
            This model could not be loaded.
        </div>

        <div v-if="state === 'ready'" class="absolute bottom-3 left-3 flex gap-2 text-xs">
            <button type="button" class="rounded-md border bg-background/80 px-2 py-1 backdrop-blur" @click="rotating = !rotating">
                {{ rotating ? 'Pause' : 'Rotate' }}
            </button>
            <button
                type="button"
                class="rounded-md border bg-background/80 px-2 py-1 backdrop-blur"
                @click="preset = preset === 'studio' ? 'soft' : 'studio'"
            >
                Light: {{ preset === 'studio' ? 'Studio' : 'Soft' }}
            </button>
            <button type="button" class="rounded-md border bg-background/80 px-2 py-1 backdrop-blur" @click="look = look === 'clay' ? 'textured' : 'clay'">
                View: {{ look === 'clay' ? 'Clay' : 'Textured' }}
            </button>
        </div>
    </div>
</template>
```

- [ ] **Step 5: Add the sidebar entry and the wallet labels**

In `resources/js/components/AppSidebar.vue`, change the icon import to `import { Box, Coins, MousePointerClick, Sparkles } from 'lucide-vue-next';` and add one entry to `mainNavItems` after the `My Creations` item:

```ts
    { title: 'Clicker maker', href: '/clickers', icon: MousePointerClick },
```

In `resources/js/pages/Credits.vue`, extend the `Entry['reason']` union with `'clicker_download' | 'clicker_refund'` and add to the `label` record:

```ts
    clicker_download: 'Clicker download unlock',
    clicker_refund: 'Clicker refund',
```

- [ ] **Step 6: Verify**

Run:

```bash
npx vue-tsc --noEmit
npx eslint resources/js/lib resources/js/components/ModelViewer.vue resources/js/components/AppSidebar.vue resources/js/pages/Credits.vue
npx prettier --write resources/js/lib/clicker.ts resources/js/lib/artwork.ts vite.config.ts
npm run build
```

Expected: no type errors in the files you touched, no lint errors, and `npm run build` succeeds (Vite must resolve `@clicker/geometry.js`). If `vue-tsc` reports `Could not find a declaration file for module '@clicker/geometry.js'`, add a file `resources/js/types/clicker-geometry.d.ts` containing `declare module '@clicker/geometry.js';` and re-run (the project already has `allowJs`, so this is normally not needed).

Then check in the browser that nothing broke: open a creation page and the home page (both use `ModelViewer` with `src`) and confirm the model still loads, rotates and switches Light and View. The sidebar now shows "Clicker maker" (the page 404s until Task 12).

- [ ] **Step 7: Commit**

```bash
git add vite.config.ts tsconfig.json resources/js
git commit -m "feat: typed access to the clicker geometry, an artwork loader and a viewer that shows in-memory parts"
```

### Task 12: The "My clickers" list page

**Files:**
- Create: `resources/js/pages/clickers/Index.vue`

**Interfaces:**
- Consumes: the `clickers/Index` props from Task 10: `clickers: ClickerData[]` (fields used: `id`, `name`, `status`, `unlocked`, `created_at`, `urls.thumbnail`) and `limit: number`.

- [ ] **Step 1: Write the page**

Create `resources/js/pages/clickers/Index.vue`:

```vue
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type Status = 'draft' | 'building' | 'ready' | 'failed';
type Clicker = { id: number; name: string; status: Status; unlocked: boolean; created_at: string; urls: { thumbnail: string | null } };

const props = defineProps<{ clickers: Clicker[]; limit: number }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Clicker maker', href: '/clickers' }];
const atLimit = computed(() => props.clickers.length >= props.limit);

const badge: Record<Status, string> = {
    draft: 'bg-muted text-muted-foreground',
    building: 'bg-amber-100 text-amber-900 dark:bg-amber-900/30 dark:text-amber-200',
    ready: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-200',
    failed: 'bg-red-100 text-red-900 dark:bg-red-900/30 dark:text-red-200',
};
const label: Record<Status, string> = { draft: 'Draft', building: 'Building files', ready: 'Files ready', failed: 'Build failed' };
const date = (iso: string) => new Date(iso).toLocaleDateString(undefined, { dateStyle: 'medium' });
</script>

<template>
    <Head title="Clicker maker" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
            <header class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Clicker maker</h1>
                    <p class="text-sm text-muted-foreground">Turn your artwork into a colour-layered fidget clicker you can print. Designing is free.</p>
                </div>
                <Button v-if="!atLimit" as-child><Link href="/clickers/create">New clicker</Link></Button>
            </header>

            <p v-if="atLimit" class="rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-700 dark:bg-amber-950/30">
                You have {{ limit }} saved clickers, the most you can keep. Delete one to start a new design.
            </p>

            <div v-if="clickers.length === 0" class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
                You haven't designed a clicker yet.
                <Link href="/clickers/create" class="underline">Make your first one</Link>.
            </div>

            <ul v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4">
                <li v-for="c in clickers" :key="c.id">
                    <Link :href="`/clickers/${c.id}`" class="block overflow-hidden rounded-xl border transition-shadow hover:shadow-md">
                        <div class="aspect-square w-full bg-muted/40">
                            <img v-if="c.urls.thumbnail" :src="c.urls.thumbnail" :alt="`${c.name} preview`" class="size-full object-cover" loading="lazy" />
                        </div>
                        <div class="space-y-1 p-3">
                            <div class="truncate font-medium">{{ c.name }}</div>
                            <div class="flex items-center justify-between gap-2 text-xs text-muted-foreground">
                                <span>{{ date(c.created_at) }}</span>
                                <span class="rounded-full px-2 py-0.5" :class="badge[c.status]">{{ label[c.status] }}</span>
                            </div>
                        </div>
                    </Link>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
```

- [ ] **Step 2: Verify**

Run: `npx vue-tsc --noEmit && npx eslint resources/js/pages/clickers/Index.vue && npx prettier --write resources/js/pages/clickers/Index.vue`
Expected: clean.

In the browser, log in with the throwaway user and open `/clickers`: expect the empty state with a "Make your first one" link. Create one design row with `php artisan tinker --execute="App\Models\Clicker::factory()->create(['user_id' => <the user's id>]);"` and reload: expect one card (no thumbnail, "Draft"). Delete the row afterwards.

- [ ] **Step 3: Commit**

```bash
git add resources/js/pages/clickers/Index.vue
git commit -m "feat: list a user's saved clicker designs"
```

### Task 13: The clicker editor

**Files:**
- Create: `resources/js/pages/clickers/Edit.vue`

**Interfaces:**
- Consumes: the `clickers/Edit` props from Task 10 (`clicker`, `bodies`, `balance`, `unlock_cost`, `max_colors`); `analyze`, `extrude`, `bodyProxy`, `paletteFor` and the types from `@/lib/clicker`; `loadArtwork`, `loadArtworkFromUrl` from `@/lib/artwork`; `ModelViewer` with `parts` and `snapshot()`; `usePolling` from `@/composables/usePolling`; `peso` from `@/lib/money`.
- Produces: the page the routes `clickers.create` and `clickers.show` render. It posts `multipart/form-data` to `POST /clickers` (create) or `POST /clickers/{id}` with `_method=put` (update), `POST /clickers/{id}/unlock`, `POST /clickers/{id}/duplicate` and `DELETE /clickers/{id}`.

- [ ] **Step 1: Write the page**

Create `resources/js/pages/clickers/Edit.vue`:

```vue
<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import ModelViewer from '@/components/ModelViewer.vue';
import { Button } from '@/components/ui/button';
import { usePolling } from '@/composables/usePolling';
import AppLayout from '@/layouts/AppLayout.vue';
import { loadArtwork, loadArtworkFromUrl } from '@/lib/artwork';
import { analyze, bodyProxy, extrude, paletteFor, type Analysis, type BodyConfig, type ClickerSettings, type Part, type Pixels } from '@/lib/clicker';
import { peso } from '@/lib/money';
import type { BreadcrumbItem } from '@/types';

type Body = { id: number; name: string; description: string | null; preview: string | null; config: BodyConfig };
type ClickerData = {
    id: number;
    name: string;
    status: 'draft' | 'building' | 'ready' | 'failed';
    error: string | null;
    settings: ClickerSettings;
    body_id: number;
    unlocked: boolean;
    editable: boolean;
    created_at: string;
    urls: { artwork: string | null; thumbnail: string | null; threemf: string | null; stl_zip: string | null };
};

const props = defineProps<{ clicker: ClickerData | null; bodies: Body[]; balance: number; unlock_cost: number; max_colors: number }>();

const emptySettings = (): ClickerSettings => ({ colors: [], scale: 1, offset_x: 0, offset_y: 0, outline_mm: 0, outline_hex: '#111111' });
const clone = <T,>(value: T): T => JSON.parse(JSON.stringify(value));

// ---- editable state
const name = ref(props.clicker?.name ?? 'My clicker');
const bodyId = ref<number>(props.clicker?.body_id ?? props.bodies[0]?.id ?? 0);
const settings = ref<ClickerSettings>(props.clicker ? clone(props.clicker.settings) : emptySettings());
const colorCount = ref(settings.value.colors.length || 4);
const pixels = shallowRef<Pixels | null>(null);
const artworkBlob = shallowRef<Blob | null>(null); // only set when the user picked a new file
const analysis = shallowRef<Analysis | null>(null);
const parts = shallowRef<Part[]>([]);
const notes = ref<string[]>([]);
const loadError = ref('');
const viewer = ref<InstanceType<typeof ModelViewer> | null>(null);

const body = computed(() => props.bodies.find((b) => b.id === bodyId.value) ?? props.bodies[0]);
const editable = computed(() => props.clicker === null || props.clicker.editable);
const previewParts = computed<Part[]>(() => (body.value ? [bodyProxy(body.value.config.cap), ...parts.value] : []));

// ---- the preview pipeline: a slow half (colours, tracing) and a fast half (heights)
const analysisKey = computed(() =>
    JSON.stringify([bodyId.value, settings.value.scale, settings.value.offset_x, settings.value.offset_y, settings.value.outline_mm, settings.value.colors.map((c) => [c.hex, c.enabled])]),
);
const heightKey = computed(() => JSON.stringify([settings.value.colors.map((c) => c.height_mm), settings.value.outline_hex]));
let timer: ReturnType<typeof setTimeout> | null = null;

function rebuild() {
    if (!analysis.value || !body.value) {
        parts.value = [];
        return;
    }
    parts.value = extrude(analysis.value, settings.value, body.value.config);
}

function runAnalysis() {
    timer = null;
    if (!pixels.value || !body.value || settings.value.colors.length === 0) {
        analysis.value = null;
        notes.value = [];
        parts.value = [];
        return;
    }
    try {
        analysis.value = analyze(pixels.value, settings.value, body.value.config);
        notes.value = analysis.value.notes;
        rebuild();
    } catch (error) {
        loadError.value = error instanceof Error ? error.message : 'The preview could not be built.';
    }
}

function schedule() {
    if (timer) clearTimeout(timer);
    timer = setTimeout(runAnalysis, 200);
}

watch([pixels, analysisKey], schedule);
watch(heightKey, rebuild);
onBeforeUnmount(() => {
    if (timer) clearTimeout(timer);
});

// ---- artwork and colours
const defaultHeight = (index: number) => Math.min(body.value?.config.max_layer_height ?? 3, Math.round((0.6 + 0.4 * index) * 10) / 10);

function resetPalette() {
    if (!pixels.value) return;
    settings.value.colors = paletteFor(pixels.value, colorCount.value).map((hex, index) => ({ hex, height_mm: defaultHeight(index), enabled: true }));
}

async function useFile(file: File | undefined) {
    if (!file || !editable.value) return;
    loadError.value = '';
    try {
        const loaded = await loadArtwork(file);
        pixels.value = loaded.pixels;
        artworkBlob.value = loaded.png;
        resetPalette();
    } catch (error) {
        loadError.value = error instanceof Error ? error.message : 'That file could not be used.';
    }
}

const onPick = (event: Event) => useFile((event.target as HTMLInputElement).files?.[0]);
const onDrop = (event: DragEvent) => useFile(event.dataTransfer?.files?.[0]);

function setColorCount(count: number) {
    colorCount.value = count;
    resetPalette();
}

function removeColor(index: number) {
    settings.value.colors.splice(index, 1);
    colorCount.value = settings.value.colors.length;
}

// Inertia may reuse this page for another design (after the first save, or after Duplicate): follow the id.
let loadedFor: number | null = props.clicker?.id ?? null;

async function loadSaved() {
    const url = props.clicker?.urls.artwork;
    if (!url) return;
    try {
        pixels.value = (await loadArtworkFromUrl(url)).pixels;
    } catch {
        loadError.value = 'The saved artwork could not be loaded.';
    }
}

onMounted(loadSaved);

// ---- saving
const snapshotKey = () => JSON.stringify([name.value, bodyId.value, settings.value]);
const savedKey = ref(snapshotKey());
const dirty = computed(() => artworkBlob.value !== null || snapshotKey() !== savedKey.value);
const saveForm = useForm<Record<string, unknown>>({});
const errors = computed(() => Object.values(saveForm.errors).filter(Boolean) as string[]);
const canSave = computed(() => editable.value && !saveForm.processing && !!body.value && (pixels.value !== null || props.clicker !== null) && settings.value.colors.length > 0);

// After a save the server sends back the stored design; take it as the new baseline.
watch(
    () => props.clicker,
    (clicker) => {
        if (!clicker) return;
        name.value = clicker.name;
        bodyId.value = clicker.body_id;
        settings.value = clone(clicker.settings);
        colorCount.value = settings.value.colors.length;
        artworkBlob.value = null;
        savedKey.value = snapshotKey();

        if (clicker.id !== loadedFor) {
            loadedFor = clicker.id;
            void loadSaved();
        }
    },
);

async function save() {
    const thumbnail = await viewer.value?.snapshot();
    const data = {
        ...(props.clicker ? { _method: 'put' } : {}),
        name: name.value,
        clicker_body_id: bodyId.value,
        settings: { ...settings.value, colors: settings.value.colors.map((c) => ({ hex: c.hex, height_mm: c.height_mm, enabled: c.enabled ? 1 : 0 })) },
        ...(artworkBlob.value ? { artwork: new File([artworkBlob.value], 'artwork.png', { type: 'image/png' }) } : {}),
        ...(thumbnail ? { thumbnail: new File([thumbnail], 'thumbnail.png', { type: 'image/png' }) } : {}),
    };

    saveForm.transform(() => data).post(props.clicker ? `/clickers/${props.clicker.id}` : '/clickers', { forceFormData: true, preserveScroll: true });
}

// ---- paying, duplicating, deleting, downloading
const unlockForm = useForm<Record<string, unknown>>({});
const canUnlock = computed(() => !!props.clicker && !props.clicker.unlocked && ['draft', 'failed'].includes(props.clicker.status));
const affordable = computed(() => props.balance >= props.unlock_cost);
const busy = computed(() => saveForm.processing || unlockForm.processing);

function unlock() {
    if (props.clicker) unlockForm.post(`/clickers/${props.clicker.id}/unlock`, { preserveScroll: true });
}

function duplicate() {
    if (props.clicker) router.post(`/clickers/${props.clicker.id}/duplicate`);
}

function remove() {
    if (props.clicker && window.confirm('Delete this design and its files?')) router.delete(`/clickers/${props.clicker.id}`);
}

const polling = usePolling({ only: ['clicker', 'balance'], active: () => props.clicker?.status === 'building' });

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Clicker maker', href: '/clickers' },
    { title: props.clicker ? props.clicker.name : 'New clicker', href: props.clicker ? `/clickers/${props.clicker.id}` : '/clickers/create' },
]);
</script>

<template>
    <Head :title="clicker ? clicker.name : 'New clicker'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div v-if="bodies.length === 0" class="mx-auto w-full max-w-xl p-6 text-center text-sm text-muted-foreground">
            No clicker bodies are available yet. Please check back soon.
        </div>

        <div v-else class="mx-auto grid w-full max-w-6xl gap-6 p-4 md:p-6 lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]">
            <!-- controls -->
            <fieldset :disabled="!editable" class="min-w-0 space-y-6 border-0 p-0">
                <p v-if="clicker && !editable" class="rounded-xl border bg-muted/40 p-3 text-sm">
                    This design is locked because it has been unlocked for download. Use <strong>Duplicate to edit</strong> to change it.
                </p>

                <section class="space-y-2">
                    <label for="clicker-name" class="text-sm font-medium">Name</label>
                    <input id="clicker-name" v-model="name" type="text" maxlength="60" class="w-full rounded-lg border bg-background px-3 py-2 text-sm" />
                </section>

                <section class="space-y-2">
                    <h2 class="text-sm font-medium">1. Clicker body</h2>
                    <div class="grid gap-2">
                        <label
                            v-for="b in bodies"
                            :key="b.id"
                            class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 text-sm"
                            :class="bodyId === b.id ? 'border-primary ring-2 ring-primary/30' : 'hover:bg-muted/40'"
                        >
                            <input v-model="bodyId" type="radio" :value="b.id" class="mt-1" />
                            <span>
                                <span class="block font-medium">{{ b.name }}</span>
                                <span v-if="b.description" class="block text-xs text-muted-foreground">{{ b.description }}</span>
                                <span class="block text-xs text-muted-foreground">Cap {{ b.config.cap.width }} × {{ b.config.cap.height }} mm<template v-if="b.config.switch"> · {{ b.config.switch }}</template></span>
                            </span>
                        </label>
                    </div>
                </section>

                <section class="space-y-2">
                    <h2 class="text-sm font-medium">2. Artwork</h2>
                    <label
                        class="flex cursor-pointer flex-col items-center gap-1 rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground hover:bg-muted/40"
                        @dragover.prevent
                        @drop.prevent="onDrop"
                    >
                        <span class="font-medium text-foreground">{{ pixels ? 'Replace artwork' : 'Drop artwork here or click to browse' }}</span>
                        <span>PNG, JPG or SVG. Transparent backgrounds work best.</span>
                        <input type="file" accept="image/png,image/jpeg,image/svg+xml" class="sr-only" @change="onPick" />
                    </label>
                    <p v-if="loadError" class="text-sm text-destructive" role="alert">{{ loadError }}</p>
                </section>

                <template v-if="pixels">
                    <section class="space-y-3">
                        <h2 class="text-sm font-medium">3. Colours</h2>
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="text-muted-foreground">Number of colours</span>
                            <button
                                v-for="n in max_colors"
                                :key="n"
                                type="button"
                                class="size-8 rounded-md border text-xs"
                                :class="colorCount === n ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted'"
                                @click="setColorCount(n)"
                            >
                                {{ n }}
                            </button>
                        </div>
                        <p class="text-xs text-muted-foreground">Changing the number picks the colours again from your artwork.</p>

                        <ul class="space-y-2">
                            <li v-for="(c, i) in settings.colors" :key="i" class="flex flex-wrap items-center gap-3 rounded-xl border p-2 text-sm">
                                <input v-model="c.hex" type="color" class="size-9 shrink-0 cursor-pointer rounded border" :aria-label="`Colour ${i + 1}`" />
                                <label class="flex items-center gap-1">
                                    <span class="text-xs text-muted-foreground">Height</span>
                                    <input
                                        v-model.number="c.height_mm"
                                        type="number"
                                        min="0.1"
                                        :max="body.config.max_layer_height"
                                        step="0.1"
                                        class="w-16 rounded-md border bg-background px-2 py-1"
                                    />
                                    <span class="text-xs text-muted-foreground">mm</span>
                                </label>
                                <label class="flex items-center gap-1 text-xs">
                                    <input v-model="c.enabled" type="checkbox" />
                                    Print
                                </label>
                                <button v-if="settings.colors.length > 1" type="button" class="ml-auto text-xs underline" @click="removeColor(i)">Remove</button>
                            </li>
                        </ul>
                    </section>

                    <section class="space-y-3">
                        <h2 class="text-sm font-medium">4. Size, position and outline</h2>
                        <label class="block text-sm">
                            <span class="flex justify-between"><span>Artwork size</span><span class="text-muted-foreground">{{ Math.round(settings.scale * 100) }}%</span></span>
                            <input v-model.number="settings.scale" type="range" min="0.1" max="1" step="0.01" class="w-full" />
                        </label>
                        <label class="block text-sm">
                            <span class="flex justify-between"><span>Move left / right</span><span class="text-muted-foreground">{{ settings.offset_x }} mm</span></span>
                            <input v-model.number="settings.offset_x" type="range" min="-20" max="20" step="0.5" class="w-full" />
                        </label>
                        <label class="block text-sm">
                            <span class="flex justify-between"><span>Move up / down</span><span class="text-muted-foreground">{{ settings.offset_y }} mm</span></span>
                            <input v-model.number="settings.offset_y" type="range" min="-20" max="20" step="0.5" class="w-full" />
                        </label>
                        <label class="block text-sm">
                            <span class="flex justify-between"><span>Outline between colours</span><span class="text-muted-foreground">{{ settings.outline_mm }} mm</span></span>
                            <input v-model.number="settings.outline_mm" type="range" min="0" max="2" step="0.1" class="w-full" />
                        </label>
                        <label v-if="settings.outline_mm > 0" class="flex items-center gap-2 text-sm">
                            <input v-model="settings.outline_hex" type="color" class="size-9 cursor-pointer rounded border" aria-label="Outline colour" />
                            Outline colour
                        </label>
                    </section>
                </template>
            </fieldset>

            <!-- preview and actions -->
            <div class="min-w-0 space-y-4 lg:sticky lg:top-4 lg:self-start">
                <ModelViewer ref="viewer" :parts="previewParts" default-look="textured" :auto-rotate="false" />

                <section v-if="notes.length" class="rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-700 dark:bg-amber-950/30" aria-label="Print notes">
                    <h2 class="mb-1 font-medium">Print notes</h2>
                    <ul class="list-inside list-disc space-y-1 text-xs">
                        <li v-for="n in notes" :key="n">{{ n }}</li>
                    </ul>
                </section>

                <section v-if="errors.length" class="rounded-xl border border-destructive/40 p-3 text-sm text-destructive" role="alert">
                    <ul class="list-inside list-disc">
                        <li v-for="e in errors" :key="e">{{ e }}</li>
                    </ul>
                </section>

                <section class="space-y-3 rounded-xl border p-4">
                    <div class="flex flex-wrap gap-3">
                        <Button v-if="editable" :disabled="!canSave" @click="save">{{ saveForm.processing ? 'Saving…' : clicker ? 'Save changes' : 'Save design' }}</Button>
                        <Button v-if="clicker" variant="outline" :disabled="busy" @click="duplicate">{{ editable ? 'Duplicate' : 'Duplicate to edit' }}</Button>
                        <Button v-if="clicker && clicker.status !== 'building'" variant="outline" :disabled="busy" @click="remove">Delete</Button>
                    </div>

                    <p v-if="!clicker" class="text-xs text-muted-foreground">Designing is free. Save your design, then unlock the print files for {{ peso(unlock_cost) }}.</p>

                    <div v-if="clicker && canUnlock" class="space-y-2 border-t pt-3">
                        <p v-if="clicker.status === 'failed'" class="text-sm text-destructive">
                            {{ clicker.error ?? 'We could not build your files.' }} You were refunded. You can try again.
                        </p>
                        <p class="text-sm">
                            Download the <strong>3MF</strong> (each colour as its own part) and a <strong>zip of one STL per colour</strong> for
                            <strong>{{ peso(unlock_cost) }}</strong>. You pay once for this design and can download both as often as you like.
                        </p>
                        <p v-if="unlockForm.errors.unlock" class="text-sm text-destructive" role="alert">{{ unlockForm.errors.unlock }}</p>
                        <p class="text-xs text-muted-foreground">Your balance: {{ peso(balance) }}</p>
                        <p v-if="dirty" class="text-xs text-muted-foreground">Save your changes first, so the files match what you see.</p>
                        <Button v-if="affordable" :disabled="busy || dirty" @click="unlock">
                            {{ unlockForm.processing ? 'Unlocking…' : `Unlock downloads (${peso(unlock_cost)})` }}
                        </Button>
                        <Button v-else as-child><Link href="/credits">Add balance to download</Link></Button>
                    </div>

                    <div v-if="clicker && clicker.status === 'building'" class="space-y-1 border-t pt-3 text-sm">
                        <p>Building your print files… this usually takes a few seconds.</p>
                        <p v-if="polling.slow.value" class="text-xs text-muted-foreground">
                            This is taking longer than expected. If it doesn't finish soon you are refunded automatically.
                        </p>
                    </div>

                    <div v-if="clicker && clicker.urls.threemf && clicker.urls.stl_zip" class="space-y-2 border-t pt-3">
                        <p class="text-sm font-medium">Your print files are ready</p>
                        <div class="flex flex-wrap gap-3">
                            <Button as-child><a :href="clicker.urls.threemf">Download 3MF</a></Button>
                            <Button variant="outline" as-child><a :href="clicker.urls.stl_zip">Download STL zip</a></Button>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            The 3MF opens as a multi-colour project in Creality Print, Bambu Studio and PrusaSlicer. Your slicer may ask which filament to use for each colour.
                        </p>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
```

- [ ] **Step 2: Type-check, lint and format**

Run:

```bash
npx vue-tsc --noEmit
npx eslint resources/js/pages/clickers/Edit.vue
npx prettier --write resources/js/pages/clickers/Edit.vue
npm run build
```

Expected: clean. Likely fixes if `vue-tsc` complains: `usePolling` returns `slow` and `failed` as refs, so the template reads `polling.slow.value` as written only if the composable returns plain refs inside a non-reactive object. Check what `resources/js/pages/stylizations/Show.vue` does (it reads `polling.slow`) and match that exactly (in a template, a ref held in a plain object is not unwrapped, so use `.value` as written here if the object is not reactive).

- [ ] **Step 3: Verify in the browser, end to end**

Start the stack (`composer dev`), log in as the throwaway user (balance at least ₱200) and:

1. Open `/clickers/create`. Expect the editor with the development body, a grey slab preview, and no colour sections yet.
2. Drop a PNG with a transparent background and 3 to 4 flat colours (any image of 600 px or more). Expect: the colours appear in the list, the preview shows the layered artwork on the slab, print notes may appear, and changing a colour's height updates the preview quickly.
3. Change the number of colours, remove one, switch one off, move the size and position sliders and set an outline. Each change must update the preview without errors in the console.
4. Drop an SVG instead. Expect it to load and preview.
5. Click **Save design**. Expect a redirect to `/clickers/{id}` with the design saved, the Save button disabled until you change something, and a thumbnail on `/clickers`.
6. Click **Unlock downloads (₱50)**. Expect "Building your print files…", then within seconds **Download 3MF** and **Download STL zip**, the balance reduced by ₱50, and the controls locked with the "Duplicate to edit" hint. (Make sure the queue worker is running, and was started after any `.env` change.)
7. Download both files. Open the 3MF in a slicer (Creality Print or similar) and confirm one object per colour plus the body, with colours attached. Unzip the STL zip and confirm one STL per colour, `body` and a README.
8. Click **Duplicate to edit**. Expect a new draft with the same artwork and settings, unlocked state cleared.
9. Check the failure path once: stop the queue worker, unlock a design, wait for the sweeper window or run `php artisan creations:sweep` after setting its `build_started_at` far in the past with tinker, and confirm the design shows "Build failed", the balance is refunded and **Unlock downloads** is offered again.
10. Confirm `/credits` lists "Clicker download unlock" and "Clicker refund" entries.

- [ ] **Step 4: Commit**

```bash
git add resources/js/pages/clickers/Edit.vue
git commit -m "feat: the clicker editor with a live layered 3D preview, a paid unlock and print downloads"
```

### Task 14: Documentation and final verification

**Files:**
- Modify: `README.md`
- Modify: `docs/superpowers/specs/2026-10-06-clicker-maker-design.md` (status line only)

- [ ] **Step 1: Document the tool**

In `README.md`, add a new section after the "Two-stage generation" section (keep the existing style: short paragraphs, a table for env keys):

```markdown
## Clicker maker

A separate tool, free to design with and paid to download. Users upload flat artwork, the browser splits it into colours and raises each colour to its own height in a live 3D preview, and after paying (default ₱50, once per design) they download a 3MF with one named part per colour plus a zip of one STL per colour.

The geometry lives in `resources/clicker/` as plain JavaScript. The editor imports it for the preview, and the queued job `BuildClickerFiles` runs the same code through Node (`resources/clicker/build.mjs`) to write the paid files, so what you see is what prints. A failed build refunds the unlock automatically and the user can unlock again; the five-minute `creations:sweep` also fails and refunds a build stuck for too long.

**Node is required on every machine that runs the queue worker** (`node --version`, then `npm ci`). Set `CLICKER_NODE_BINARY` if it is not on the PATH.

| Key | Default | Meaning |
|---|---|---|
| `CREDITS_CLICKER_COST` | `50` | Price to unlock one design's downloads. |
| `CLICKER_MAX_PER_USER` | `20` | Saved designs per user. |
| `CLICKER_NODE_BINARY` | `node` | The Node executable used by the build job. |
| `CLICKER_BUILD_TIMEOUT` | `90` | Seconds before a build process is killed. |

**Adding a clicker body.** Bodies are model files you have printed and tested. Put the STL (or several) in `resources/clicker/bodies/<slug>/` and add an entry to `database/seeders/ClickerBodySeeder.php` with the cap size, the artwork area, the highest layer, the smallest printable detail and the switch it takes (see `ClickerBody::sampleConfig()` for every field and its unit). Run `php artisan db:seed --class=ClickerBodySeeder`. The development body `placeholder-slab` has no switch cavity: deactivate it (`active => false`) before launch.

**Tests.** `npm run test:clicker` runs the geometry suite (Node); `vendor/bin/pest` runs the rest. The Pest suite refuses to start unless it is on in-memory SQLite: run `php artisan config:clear` first and never run `php artisan config:cache` on a development machine.
```

- [ ] **Step 2: Mark the spec as implemented**

In `docs/superpowers/specs/2026-10-06-clicker-maker-design.md`, change the line `Status: Draft for review` to `Status: Implemented`.

- [ ] **Step 3: Run every check**

```bash
php artisan config:clear
vendor/bin/pest
npm run test:clicker
npx vue-tsc --noEmit
npx eslint resources/js resources/clicker
npm run build
```

Expected: all green (the full Pest suite, 36 Node tests, no type or lint errors, a successful production build). If `eslint` reports problems in files you did not touch, leave them alone and mention them.

- [ ] **Step 4: Commit**

```bash
git add README.md docs/superpowers/specs/2026-10-06-clicker-maker-design.md
git commit -m "docs: document the clicker maker, its Node requirement and how to add bodies"
```

---

## Self-review notes (spec coverage)

- Own artwork to layered clicker, 1 to 8 colours, per-colour heights, optional outline: Tasks 1 to 4 and 13.
- Customer downloads (3MF + STL zip), ₱50 once per design, free to design: Tasks 3, 8, 10, 13.
- Bodies supplied by the owner, development placeholder: Task 6 and the README (Task 14).
- Hybrid architecture (browser preview, server-built files, shared module): Tasks 4, 9, 11, 13.
- Paid design locked, Duplicate to edit: Tasks 8, 10, 13.
- Refund on failed build, retry as a new numbered attempt, sweeper: Tasks 5, 8, 9.
- Security: PNG only on the wire and re-encoded with GD, no SVG parsing on the server, fixed arguments for the Node process with timeout and memory cap, body files restricted to `resources/clicker/bodies`, owner-only file routes with `nosniff` and a sandbox CSP: Tasks 7, 9, 10.
- Print notes (specks merged, thin details, absent colours): Task 4 and 13.
- Limits (20 designs, 10 MB, 256 to 4096 px, 40 megapixels, 8 colours): Tasks 6, 7, 10.
- Out of scope, as in the spec: ordering printed clickers, AI-generated artwork, the press animation, several artworks per design, vector-exact SVG, a public gallery.
