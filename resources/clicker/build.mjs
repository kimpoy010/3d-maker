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
    // Print layout: the switch base is flipped plate down and placed beside the cap.
    const parts = buildParts(analysis, job.settings, job.body, { layout: 'print' });
    // A generated keycap's cap and base are always there; it is the artwork that has to print.
    if (!parts.some((part) => part.kind !== 'body' && part.kind !== 'base')) throw new Error('The artwork has no printable colour on this body.');

    // A generated keycap body makes its cap and base itself; only a legacy body brings its mesh files.
    const generated = job.body?.generator === 'mx_keycap';
    for (const file of generated ? [] : (job.bodyFiles ?? [])) {
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
