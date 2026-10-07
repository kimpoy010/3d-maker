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
    assert.deepEqual(
        suggestPalette(
            makeImage(4, 4, () => null),
            3,
        ),
        [],
    );
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

test('cleanSpecks does not swallow a big region that a merged speck joined to a small one', () => {
    // The lone 1 sits between a big 0 region and a tiny 0 region. Filling it joins them into one region
    // of 8 pixels, which is no longer small and must stay.
    const { labels, width, height } = gridFromRows(['.........', '.00000100.', '.........']);
    cleanSpecks(labels, width, height, 4);

    const zeros = [...labels].filter((label) => label === 0).length;
    assert.equal(zeros, 8, 'the joined region survived');
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
