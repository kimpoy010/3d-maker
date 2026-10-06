import { strFromU8, unzipSync } from 'fflate';
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { encodeStlZip, encodeThreeMf, stlFileName } from '../exports.js';
import { extrudePolygons } from '../mesh.js';
import { encodeStl, parseStl } from '../stl.js';

const square = (hex, kind = 'colour', name = `Colour ${hex}`) => ({
    kind,
    name,
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
    assert.equal(
        vertices,
        parts.reduce((n, p) => n + p.positions.length / 3, 0),
    );
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
