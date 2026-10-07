import { strFromU8, unzipSync } from 'fflate';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { PNG } from 'pngjs';
import { analyzeArtwork, buildParts } from '../geometry.js';
import { bounds, isClosedManifold } from '../mesh.js';
import { BODY, KEYCAP_BODY, SETTINGS, logoArtwork, makeImage, sampleArtwork } from './helpers.js';

const BUILD = fileURLToPath(new URL('../build.mjs', import.meta.url));
const BLOCKER = 'This shape is too thin to hold the switch stem. Make the artwork larger or choose Square or Circle.';
const EPS = 1e-3;

const thinLine = () => makeImage(220, 220, (x, y) => (x >= 10 && x < 210 && y >= 108 && y < 112 ? [29, 78, 216] : null));

for (const shape of ['square', 'circle', 'trace']) {
    test(`a ${shape} keycap: colour parts sit on the body, then come the cap and the base`, () => {
        const settings = { ...SETTINGS, base_shape: shape };
        const analysis = analyzeArtwork(sampleArtwork(), settings, KEYCAP_BODY, 'preview');
        const parts = buildParts(analysis, settings, KEYCAP_BODY);

        assert.deepEqual(analysis.blockers, []);
        assert.ok(Array.isArray(analysis.cap.outline) && analysis.cap.outline.length >= 3);
        assert.equal(parts.at(-1).kind, 'base');
        const body = parts.at(-2);
        assert.equal(body.kind, 'body');
        assert.equal(body.name, 'Cap');
        assert.ok(isClosedManifold(body));

        const [bx0, by0, bz0, bx1, by1, bz1] = bounds(body);
        assert.ok(Math.abs(bz0) < EPS && Math.abs(bz1 - 8) < EPS);
        const colours = parts.filter((p) => p.kind === 'colour');
        assert.equal(colours.length, 3);
        for (const part of colours) {
            const [x0, y0, z0, x1, y1] = bounds(part);
            assert.ok(Math.abs(z0 - 8) < EPS, `${part.name} starts at z ${z0}`);
            assert.ok(x0 >= bx0 - EPS && y0 >= by0 - EPS && x1 <= bx1 + EPS && y1 <= by1 + EPS, `${part.name} is inside the body`);
        }

        if (shape !== 'trace') {
            assert.ok(Math.abs(bx0 + 15) < EPS && Math.abs(bx1 - 15) < EPS, 'a 30 mm body');
            assert.deepEqual(analysis.cap.stem, { x: 0, y: 0 });
        }
    });
}

test('square and circle keep the artwork inside the rim; trace fits it to the full size', () => {
    const width = (shape) => {
        const settings = { ...SETTINGS, base_shape: shape };
        const parts = buildParts(analyzeArtwork(sampleArtwork(), settings, KEYCAP_BODY, 'preview'), settings, KEYCAP_BODY);
        const box = bounds(parts.find((p) => p.name.startsWith('Colour 1')));

        return box[3] - box[0];
    };
    // The 240 px sample has its art in the middle 200 px.
    assert.ok(Math.abs(width('square') - (26 * 200) / 240) < 0.2);
    assert.ok(Math.abs(width('circle') - (26 * 200) / 240) < 0.2);
    assert.ok(Math.abs(width('trace') - (30 * 200) / 240) < 0.2);
});

test('a missing or unknown base shape is treated as square', () => {
    const outline = (settings) => analyzeArtwork(sampleArtwork(), settings, KEYCAP_BODY, 'preview').cap.outline;
    const square = outline({ ...SETTINGS, base_shape: 'square' });

    assert.deepEqual(outline(SETTINGS), square);
    assert.deepEqual(outline({ ...SETTINGS, base_shape: 'hexagon' }), square);
});

test('the circle keycap clips full-bleed artwork to the circle inside the rim', () => {
    const big = { ...SETTINGS, base_shape: 'circle', scale: 2 };
    const parts = buildParts(analyzeArtwork(sampleArtwork(), big, KEYCAP_BODY, 'preview'), big, KEYCAP_BODY);
    const radius = 15 - 2; // size / 2 - rim
    let reach = 0;
    for (const part of parts.filter((p) => p.kind === 'colour')) {
        const { positions } = part;
        // Pixels are kept when their centre is inside, so a corner may stick out by half a pixel diagonal.
        const slack = (Math.SQRT2 / 2) * ((26 * 2) / 240);
        for (let i = 0; i < positions.length; i += 3) {
            const r = Math.hypot(positions[i], positions[i + 1]);
            reach = Math.max(reach, r);
            assert.ok(r <= radius + slack + EPS, `vertex at radius ${r}`);
        }
    }
    assert.ok(reach > radius - 1, `artwork reaches the clip edge (${reach})`);
});

test('the square keycap mask and the trace layout are unchanged', () => {
    const reach = (shape) => {
        const settings = { ...SETTINGS, base_shape: shape, scale: 2 };
        const parts = buildParts(analyzeArtwork(sampleArtwork(), settings, KEYCAP_BODY, 'preview'), settings, KEYCAP_BODY);
        const xs = parts.filter((p) => p.kind === 'colour').map((p) => bounds(p));

        return Math.max(...xs.map((b) => Math.max(b[3], b[4])));
    };

    assert.ok(reach('square') > 13.5 && reach('square') <= 15 + EPS, 'square art may extend into the rim');
    assert.ok(reach('trace') > 0);
});

test('a legacy body keeps its old behaviour: no body part, no cap analysis, no blockers', () => {
    const analysis = analyzeArtwork(sampleArtwork(), SETTINGS, BODY, 'preview');
    const parts = buildParts(analysis, SETTINGS, BODY);

    assert.equal(analysis.cap, undefined);
    assert.deepEqual(analysis.blockers, []);
    assert.ok(parts.every((p) => p.kind !== 'body'));
});

test('a trace shape too thin for the stem is a blocker and buildParts refuses it', () => {
    const settings = { ...SETTINGS, base_shape: 'trace' };
    const analysis = analyzeArtwork(thinLine(), settings, KEYCAP_BODY, 'preview');

    assert.deepEqual(analysis.blockers, [BLOCKER]);
    assert.throws(() => buildParts(analysis, settings, KEYCAP_BODY), { message: BLOCKER });

    // The same artwork on a square keycap is fine.
    const square = { ...SETTINGS, base_shape: 'square' };
    assert.deepEqual(analyzeArtwork(thinLine(), square, KEYCAP_BODY, 'preview').blockers, []);
});

test('trace notes reach the analysis notes', () => {
    const blocks = makeImage(800, 100, (x) => (x < 100 || x >= 700 ? [29, 78, 216] : null));
    const settings = { ...SETTINGS, base_shape: 'trace' };
    const analysis = analyzeArtwork(blocks, settings, KEYCAP_BODY, 'preview');

    assert.ok(analysis.notes.includes('The artwork has separate pieces, so the shape joins them with a wrapped outline.'));
});

function writeJob(image, settings, body) {
    const dir = mkdtempSync(join(tmpdir(), 'clicker-job-'));
    const png = new PNG({ width: image.width, height: image.height });
    png.data = Buffer.from(image.data);
    writeFileSync(join(dir, 'artwork.png'), PNG.sync.write(png));
    writeFileSync(join(dir, 'job.json'), JSON.stringify({ settings, body, title: 'Keycap', folder: 'clicker-1' }));

    return dir;
}

test('build.mjs builds a trace keycap with a generated Cap part', () => {
    const dir = writeJob(logoArtwork(300), { ...SETTINGS, base_shape: 'trace' }, KEYCAP_BODY);
    const run = spawnSync(process.execPath, [BUILD, dir], { encoding: 'utf8' });

    assert.equal(run.status, 0, run.stderr);
    const model = strFromU8(unzipSync(readFileSync(join(dir, 'clicker.3mf')))['3D/3dmodel.model']);
    const names = [...model.matchAll(/<object [^>]*name="([^"]*)"/g)].map((m) => m[1]);
    assert.equal(names.at(-2), 'Cap');
    assert.equal(names.filter((n) => n === 'Cap').length, 1, 'exactly one Cap object');
    const result = JSON.parse(readFileSync(join(dir, 'result.json'), 'utf8'));
    assert.equal(result.parts.at(-2).kind, 'body');
    assert.equal(result.parts.at(-1).kind, 'base');
});

test('build.mjs still fails when a keycap has no printable colour, and on a blocker', () => {
    const empty = writeJob(
        makeImage(20, 20, () => null),
        { ...SETTINGS, base_shape: 'square' },
        KEYCAP_BODY,
    );
    const run = spawnSync(process.execPath, [BUILD, empty], { encoding: 'utf8' });
    assert.equal(run.status, 1);
    assert.match(run.stderr, /no printable colour/);

    const thin = writeJob(thinLine(), { ...SETTINGS, base_shape: 'trace' }, KEYCAP_BODY);
    const blocked = spawnSync(process.execPath, [BUILD, thin], { encoding: 'utf8' });
    assert.equal(blocked.status, 1);
    assert.match(blocked.stderr, /too thin to hold the switch stem/);
});

for (const shape of ['square', 'circle', 'trace']) {
    test(`a 500 px logo on a ${shape} keycap analyses and builds quickly`, () => {
        const image = logoArtwork(500);
        const settings = { ...SETTINGS, base_shape: shape };
        for (const [quality, limit] of [
            ['preview', 1500],
            ['final', 5000],
        ]) {
            const started = performance.now();
            buildParts(analyzeArtwork(image, settings, KEYCAP_BODY, quality), settings, KEYCAP_BODY);
            const took = performance.now() - started;
            assert.ok(took < limit, `${quality} took ${Math.round(took)} ms`);
        }
    });
}
