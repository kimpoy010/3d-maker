import assert from 'node:assert/strict';
import { test } from 'node:test';
import { analyzeArtwork, prepareArtwork, suggestPalette } from '../geometry.js';
import { removeBackground } from '../raster.js';
import { KEYCAP_BODY, SETTINGS, makeImage } from './helpers.js';

const SIZE = 300;
const WHITE = [255, 255, 255];
const BLUE = [29, 78, 216];

/** A blue disc (radius 100) with an inner white "letter" block, on the given background. */
const logo = (background = WHITE) =>
    makeImage(SIZE, SIZE, (x, y) => {
        const d = Math.hypot(x - 150, y - 150);
        if (d >= 100) return background;
        if (Math.abs(x - 150) < 20 && Math.abs(y - 150) < 40) return WHITE;

        return BLUE;
    });

const alphaAt = (image, x, y) => image.data[(y * image.width + x) * 4 + 3];
const count = (image, test) => {
    let n = 0;
    for (let i = 0; i < image.width * image.height; i++) if (test(image.data[i * 4 + 3])) n++;

    return n;
};

test('a white background goes transparent and the enclosed white stays', () => {
    const out = removeBackground(logo());

    assert.equal(alphaAt(out, 2, 2), 0);
    assert.equal(alphaAt(out, 297, 150), 0);
    assert.equal(alphaAt(out, 150, 150), 255, 'inner white kept');
    assert.equal(alphaAt(out, 150, 110), 255, 'disc kept');
});

test('a coloured uniform border works', () => {
    const out = removeBackground(logo([250, 220, 120]));

    assert.equal(alphaAt(out, 5, 5), 0);
    assert.equal(alphaAt(out, 150, 150), 255);
});

test('a noisy border leaves the image unchanged', () => {
    let seed = 7;
    const rand = () => (seed = (seed * 16807) % 2147483647) / 2147483647;
    const noisy = makeImage(SIZE, SIZE, (x, y) => {
        if (x === 0 || y === 0 || x === SIZE - 1 || y === SIZE - 1) return [rand() * 255, rand() * 255, rand() * 255];

        return Math.hypot(x - 150, y - 150) < 100 ? BLUE : WHITE;
    });
    const out = removeBackground(noisy);

    assert.deepEqual(Array.from(out.data), Array.from(noisy.data));
});

test('an image with real transparency is returned unchanged', () => {
    const image = makeImage(SIZE, SIZE, (x, y) => (Math.hypot(x - 150, y - 150) < 100 ? BLUE : null));
    const out = removeBackground(image);

    assert.deepEqual(Array.from(out.data), Array.from(image.data));
});

test('tolerance: a pale grey gradient border is removed, a dark ring is not', () => {
    const pale = makeImage(SIZE, SIZE, (x, y) => (Math.hypot(x - 150, y - 150) < 100 ? BLUE : [235 + Math.floor((x / SIZE) * 15), 235, 235]));
    assert.equal(alphaAt(removeBackground(pale), 3, 3), 0);

    const ringed = makeImage(SIZE, SIZE, (x, y) => {
        if (x < 10 || y < 10 || x >= SIZE - 10 || y >= SIZE - 10) return [40, 40, 40];

        return Math.hypot(x - 150, y - 150) < 100 ? BLUE : WHITE;
    });
    const out = removeBackground(ringed);
    // The border is uniformly dark, so the dark ring is the background and goes; the white behind it is enclosed and stays.
    assert.equal(alphaAt(out, 3, 3), 0);
    assert.equal(alphaAt(out, 20, 20), 255);
});

test('removeBackground does not mutate its input', () => {
    const image = logo();
    const copy = Uint8ClampedArray.from(image.data);
    const out = removeBackground(image);

    assert.notEqual(out.data, image.data);
    assert.deepEqual(Array.from(image.data), Array.from(copy));
});

const area = (ring) => {
    let sum = 0;
    for (let i = 0; i < ring.length; i++) {
        const [x0, y0] = ring[i];
        const [x1, y1] = ring[(i + 1) % ring.length];
        sum += x0 * y1 - x1 * y0;
    }

    return Math.abs(sum / 2);
};

test('trace hugs the disc after background removal and is the full square without it', () => {
    const settings = { ...SETTINGS, base_shape: 'trace' };
    const traced = analyzeArtwork(logo(), settings, KEYCAP_BODY, 'preview').cap.outline;
    const raw = analyzeArtwork(logo(), { ...settings, remove_background: false }, KEYCAP_BODY, 'preview').cap.outline;

    // The disc is 200 of 300 px across; trace fits the artwork to 30 mm, so the disc is 20 mm across.
    const disc = Math.PI * 10 * 10;
    const rimmed = Math.PI * (10 + 3) ** 2; // generous rim allowance
    assert.ok(area(traced) < rimmed * 1.2 + 1 && area(traced) > disc * 0.8, `traced area ${area(traced)}`);
    assert.ok(area(raw) > 30 * 30 * 0.8, `raw area ${area(raw)}`);
});

test('suggestPalette on the prepared logo has no white', () => {
    // A blue disc and a teal ring on white, with no enclosed white.
    const plain = makeImage(SIZE, SIZE, (x, y) => {
        const d = Math.hypot(x - 150, y - 150);
        if (d < 70) return BLUE;
        if (d > 85 && d < 100) return [13, 148, 136];

        return d < 100 ? BLUE : WHITE;
    });
    const before = suggestPalette(plain, 3);
    const palette = suggestPalette(prepareArtwork(plain, {}), 3);

    assert.ok(
        before.some((hex) => hex === '#FFFFFF'),
        'white is suggested without removal',
    );
    assert.ok(!palette.includes('#FFFFFF'), palette.join());
    assert.ok(palette.length > 0);
});

test('prepareArtwork returns the image itself when remove_background is false', () => {
    const image = logo();

    assert.equal(prepareArtwork(image, { remove_background: false }), image);
    assert.ok(count(prepareArtwork(image, {}), (a) => a === 0) > 0);
});
