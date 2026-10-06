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
import { extrudePolygons } from '../mesh.js';
import { encodeStl } from '../stl.js';
import { BODY, SETTINGS, sampleArtwork } from './helpers.js';

const BUILD = fileURLToPath(new URL('../build.mjs', import.meta.url));

const square = (hex) => ({
    kind: 'body',
    name: 'Body',
    hex,
    ...extrudePolygons(
        [
            {
                outer: [
                    [0, 0],
                    [2, 0],
                    [2, 2],
                    [0, 2],
                ],
                holes: [],
            },
        ],
        0,
        1,
    ),
});

test('build.mjs turns a job folder into print files', () => {
    const dir = mkdtempSync(join(tmpdir(), 'clicker-job-'));
    const image = sampleArtwork();
    const png = new PNG({ width: image.width, height: image.height });
    png.data = Buffer.from(image.data);
    writeFileSync(join(dir, 'artwork.png'), PNG.sync.write(png));

    const bodyStl = join(dir, 'body.stl');
    writeFileSync(bodyStl, encodeStl(square('#9CA3AF')));
    writeFileSync(
        join(dir, 'job.json'),
        JSON.stringify({ settings: SETTINGS, body: BODY, bodyFiles: [{ path: bodyStl, name: 'Body' }], title: 'Test clicker', folder: 'clicker-1' }),
    );

    const run = spawnSync(process.execPath, [new URL('../build.mjs', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1'), dir], {
        encoding: 'utf8',
    });

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
