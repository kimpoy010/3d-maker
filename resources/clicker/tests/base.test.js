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
import { baseFits, buildBase, keycapConfig, squareOutline } from '../keycap.js';
import { bounds, isClosedManifold, meshVolume } from '../mesh.js';
import { pointInPolygons } from '../solids.js';
import { parseStl } from '../stl.js';
import { signedArea } from '../trace.js';
import { KEYCAP_BODY, SETTINGS, logoArtwork, makeImage, materialAlong } from './helpers.js';

const BUILD = fileURLToPath(new URL('../build.mjs', import.meta.url));
const FIT_BLOCKER = 'This shape is too small to hold the switch base. Make the artwork larger or choose Square or Circle.';
const FAILED_BLOCKER = 'The switch base could not be built for this shape. Make the artwork larger or choose Square or Circle.';
const EPS = 1e-3;

const keycap = keycapConfig({});
const { height: HB, plate: PLATE, cutout: CUT, gap: GAP, spacing: SPACING } = keycap.base;

const near = (actual, expected, relative, label) =>
    assert.ok(Math.abs(actual - expected) <= Math.abs(expected) * relative, `${label}: ${actual} vs ${expected}`);

const polygonArea = (polygons) =>
    polygons.reduce((sum, { outer, holes }) => sum + Math.abs(signedArea(outer)) - holes.reduce((s, h) => s + Math.abs(signedArea(h)), 0), 0);

const assertIntervals = (actual, expected, label) => {
    assert.equal(actual.length, expected.length, `${label}: ${JSON.stringify(actual)}`);
    actual.forEach(([a, b], i) => {
        assert.ok(Math.abs(a - expected[i][0]) < EPS && Math.abs(b - expected[i][1]) < EPS, `${label}: ${JSON.stringify(actual)}`);
    });
};

/** A grid point that `accept`s, at least `margin` from every listed vertex. */
function findPoint(rings, accept, margin = 0.3) {
    const vertices = rings.flat();
    const [x0, y0, x1, y1] = vertices.reduce(
        (b, [x, y]) => [Math.min(b[0], x), Math.min(b[1], y), Math.max(b[2], x), Math.max(b[3], y)],
        [Infinity, Infinity, -Infinity, -Infinity],
    );
    for (let y = y0 + 0.0137; y < y1; y += 0.11) {
        for (let x = x0 + 0.0213; x < x1; x += 0.11) {
            if (!accept([x, y])) continue;
            if (vertices.every(([vx, vy]) => Math.hypot(vx - x, vy - y) > margin)) return [x, y];
        }
    }
    throw new Error('no point found');
}

const ringsOf = (polygons) => polygons.flatMap(({ outer, holes }) => [outer, ...holes]);

/** Check the base in its own frame (z 0..height) against the spec, given a transform of points into the part. */
function checkBase(part, { outline, baseInset: inset, stem }, place = ([x, y]) => [x, y], z = (v) => v) {
    assert.equal(part.kind, 'base');
    assert.equal(part.name, 'Base');
    assert.equal(part.hex, '#6B7280');
    assert.ok(isClosedManifold(part), 'manifold');

    const expected = Math.abs(signedArea(outline)) * HB - polygonArea(inset) * (HB - PLATE) - CUT * CUT * PLATE;
    near(meshVolume(part), expected, 0.01, 'volume');

    const zs = [z(0), z(HB)].sort((a, b) => a - b);
    const [, , z0, , , z1] = bounds(part);
    assert.ok(Math.abs(z0 - zs[0]) < EPS && Math.abs(z1 - zs[1]) < EPS, `z ${z0}..${z1}`);

    const ray = (point) => materialAlong(part, ...place(point));
    const span = (a, b) => [[Math.min(z(a), z(b)), Math.max(z(a), z(b))]];

    assertIntervals(ray([stem.x + 0.011, stem.y + 0.007]), [], 'stem ray: nothing in the cutout column');
    const reach = CUT / 2 + 0.3;
    const wall = findPoint([outline, ...ringsOf(inset)], (p) => pointInPolygons(p, [{ outer: outline, holes: [] }]) && !pointInPolygons(p, inset));
    assertIntervals(ray(wall), span(0, HB), 'wall ray');
    const cavity = findPoint(
        ringsOf(inset),
        (p) => pointInPolygons(p, inset) && (Math.abs(p[0] - stem.x) > reach || Math.abs(p[1] - stem.y) > reach),
    );
    assertIntervals(ray(cavity), span(HB - PLATE, HB), 'cavity ray: only the plate');
    const cutoutRim = [stem.x + CUT / 2 - 0.2, stem.y + 0.0071];
    assertIntervals(ray(cutoutRim), [], 'just inside the cutout');
}

const analyse = (shape, image = logoArtwork(300), extra = {}) => {
    const settings = { ...SETTINGS, base_shape: shape, ...extra };
    return { settings, analysis: analyzeArtwork(image, settings, KEYCAP_BODY, 'preview') };
};

const solidBlue = () => makeImage(40, 40, () => [29, 78, 216]);

test('keycapConfig carries the base defaults and deep merges them', () => {
    assert.deepEqual(keycap.base, { height: 12, plate: 1.5, wall: 2.0, cutout: 14.1, gap: 4.0, spacing: 10, hex: '#6B7280' });
    const merged = keycapConfig({ keycap: { base: { height: 14 } } });
    assert.equal(merged.base.height, 14);
    assert.equal(merged.base.cutout, 14.1);
    assert.deepEqual(keycapConfig({ keycap: { size: 30 } }).hollow, { wall: 1.6, plate: 2.0, boss_radius: 3.6 });
});

for (const shape of ['square', 'circle', 'trace']) {
    test(`${shape} base: one closed shell with the cutout, plate and cavity`, () => {
        const image = shape === 'trace' ? logoArtwork(300) : solidBlue();
        const { analysis, settings } = analyse(shape, image);
        const { stem, baseInset } = analysis.cap;

        assert.deepEqual(analysis.blockers, []);
        assert.ok(baseInset.length >= 1);
        assert.ok(baseFits({ inset: baseInset, stem, keycap }));

        const parts = buildParts(analysis, settings, KEYCAP_BODY);
        assert.deepEqual(
            parts.slice(-2).map((p) => p.name),
            ['Cap', 'Base'],
            'the base comes last, after the cap',
        );
        const base = parts.at(-1);
        // Assembled: the base sits below the cap with the gap.
        checkBase(base, analysis.cap, undefined, (z) => z - (GAP + HB));
        const [, , z0, , , z1] = bounds(base);
        assert.ok(Math.abs(z0 + GAP + HB) < EPS && Math.abs(z1 + GAP) < EPS, `assembled base z ${z0}..${z1}`);
    });
}

test('the analytic insets are the outline inset by the base wall', () => {
    for (const shape of ['square', 'circle']) {
        const { analysis } = analyse(shape, solidBlue());
        const [{ outer }] = analysis.cap.baseInset;
        const xs = outer.map((p) => p[0]);
        near(Math.max(...xs), 15 - 2, 1e-9, `${shape} inset reach`);
    }
});

test('the assembled layout keeps the cap and colours exactly where they were', () => {
    for (const hollow of [false, true]) {
        const { analysis, settings } = analyse('trace', logoArtwork(300), { hollow_cap: hollow });
        const assembled = buildParts(analysis, settings, KEYCAP_BODY);
        const explicit = buildParts(analysis, settings, KEYCAP_BODY, { layout: 'assembled' });
        const printed = buildParts(analysis, settings, KEYCAP_BODY, { layout: 'print' });

        assert.equal(assembled.length, printed.length);
        for (let i = 0; i < assembled.length - 1; i++) {
            assert.deepEqual(printed[i].positions, assembled[i].positions, `${assembled[i].name} unchanged in print`);
            assert.deepEqual(explicit[i].positions, assembled[i].positions);
        }
        assert.deepEqual(explicit.at(-1).positions, assembled.at(-1).positions, 'assembled is the default');
        const cap = assembled.find((p) => p.name === 'Cap');
        const [, , cz0, , , cz1] = bounds(cap);
        assert.ok(Math.abs(cz0) < EPS && Math.abs(cz1 - 8) < EPS);
        for (const part of assembled.filter((p) => p.kind === 'colour')) assert.ok(Math.abs(bounds(part)[2] - 8) < EPS);
    }
});

for (const shape of ['square', 'circle', 'trace']) {
    test(`${shape} print layout: the base is flipped plate down and placed clear of the cap`, () => {
        const image = shape === 'trace' ? logoArtwork(300) : solidBlue();
        const { analysis, settings } = analyse(shape, image);
        const parts = buildParts(analysis, settings, KEYCAP_BODY, { layout: 'print' });
        const base = parts.at(-1);
        const xs = analysis.cap.outline.map((p) => p[0]);
        const shift = Math.max(...xs) - Math.min(...xs) + SPACING;

        // (x, y, z) -> (x + shift, -y, height - z)
        checkBase(
            base,
            analysis.cap,
            ([x, y]) => [x + shift, -y],
            (z) => HB - z,
        );
        const [bx0] = bounds(base);
        const capRight = Math.max(...parts.slice(0, -1).map((p) => bounds(p)[3]));
        assert.ok(bx0 >= capRight + SPACING - EPS, `base starts at x ${bx0}, the cap ends at ${capRight}`);
        near(meshVolume(base), meshVolume(buildParts(analysis, settings, KEYCAP_BODY).at(-1)), 1e-6, 'same volume as assembled');
    });
}

test('the hollow cap and the base build together', () => {
    const { analysis, settings } = analyse('square', solidBlue(), { hollow_cap: true });
    const parts = buildParts(analysis, settings, KEYCAP_BODY);
    const cap = parts.find((p) => p.name === 'Cap');

    assert.equal(cap, parts.at(-2));
    near(meshVolume(cap), meshVolume(analysis.cap.hollowBody), 1e-9, 'the hollow cap is used');
    checkBase(parts.at(-1), analysis.cap, undefined, (z) => z - (GAP + HB));
});

test('the base from the analysis is reused when only heights change', () => {
    const { analysis, settings } = analyse('trace');
    const taller = { ...settings, colors: settings.colors.map((c) => ({ ...c, height_mm: c.height_mm + 0.5 })) };
    const a = buildParts(analysis, settings, KEYCAP_BODY, { layout: 'print' });
    const b = buildParts(analysis, taller, KEYCAP_BODY, { layout: 'print' });

    assert.deepEqual(b.at(-1).positions, a.at(-1).positions);
    assert.deepEqual(b.at(-1).indices, a.at(-1).indices);
    assert.notDeepEqual(bounds(b[0]), bounds(a[0]));
    // The analysis keeps the unplaced base; layouts never change it.
    const [, , z0, , , z1] = bounds(analysis.cap.baseBody);
    assert.ok(Math.abs(z0) < EPS && Math.abs(z1 - HB) < EPS);
});

test('a trace shape too small for the cutout is a blocker and buildParts refuses it', () => {
    // A 3 mm radius dot fitted into 30 mm: the outline is a ~5 mm disc that holds the stem but not the switch.
    const dot = makeImage(300, 300, (x, y) => (Math.hypot(x + 0.5 - 150, y + 0.5 - 150) < 30 ? [29, 78, 216] : null));
    const { analysis, settings } = analyse('trace', dot);

    assert.deepEqual(analysis.blockers, [FIT_BLOCKER]);
    assert.throws(() => buildParts(analysis, settings, KEYCAP_BODY), { message: FIT_BLOCKER });
    assert.throws(() => buildParts(analysis, settings, KEYCAP_BODY, { layout: 'print' }), { message: FIT_BLOCKER });
});

test('a square keycap too small for the cutout gets the same blocker', () => {
    const body = { ...KEYCAP_BODY, keycap: { size: 18 } };
    const settings = { ...SETTINGS, base_shape: 'square' };
    const analysis = analyzeArtwork(solidBlue(), settings, body, 'preview');

    assert.deepEqual(analysis.blockers, [FIT_BLOCKER]);
    assert.throws(() => buildParts(analysis, settings, body), { message: FIT_BLOCKER });
});

test('baseFits checks the expanded cutout against the inset and its islands', () => {
    const inset = (size) => [{ outer: squareOutline({ size, corner_radius: 0 }), holes: [] }];
    const stem = { x: 0, y: 0 };
    const need = CUT + 1.6;

    assert.ok(baseFits({ inset: inset(need + 0.2), stem, keycap }));
    assert.ok(!baseFits({ inset: inset(need - 0.2), stem, keycap }), 'too tight');
    assert.ok(!baseFits({ inset: inset(40), stem: { x: 13, y: 0 }, keycap }), 'off to one side');
    assert.ok(!baseFits({ inset: [], stem, keycap }), 'nothing left');

    const island = (cx, cy) => [
        [cx - 0.5, cy - 0.5],
        [cx - 0.5, cy + 0.5],
        [cx + 0.5, cy + 0.5],
        [cx + 0.5, cy - 0.5],
    ];
    const withIsland = (cx, cy) => [{ outer: squareOutline({ size: 40, corner_radius: 2 }), holes: [island(cx, cy)] }];
    assert.ok(baseFits({ inset: withIsland(15, 15), stem, keycap }), 'a far island is fine');
    assert.ok(!baseFits({ inset: withIsland(0, 3), stem, keycap }), 'an island inside the square');
    assert.ok(!baseFits({ inset: withIsland(CUT / 2 + 0.8, 3), stem, keycap }), 'an island touching the square edge');

    // A notch reaching into the square between the sampled corners and midpoints.
    const notched = [
        {
            outer: [
                [-20, -20],
                [20, -20],
                [20, 20],
                [4, 20],
                [3.5, 4],
                [3, 20],
                [-20, 20],
            ],
            holes: [],
        },
    ];
    assert.ok(!baseFits({ inset: notched, stem, keycap }), 'an inset edge crossing the square');
});

test('a base that fails verification is never returned; the analysis gets the build blocker', () => {
    const outline = squareOutline(keycap);
    const stem = { x: 0, y: 0 };
    const good = [{ outer: squareOutline({ size: 26, corner_radius: 2 }), holes: [] }];
    assert.ok(isClosedManifold(buildBase({ outline, inset: good, stem, keycap })));

    // An island touching the inset's outer ring at one vertex: the vertical edge there has four wall triangles.
    const touching = [
        {
            outer: [
                [-12, -12],
                [12, -12],
                [12, 0],
                [12, 12],
                [-12, 12],
            ],
            holes: [
                [
                    [12, 0],
                    [9, -1.5],
                    [9, 1.5],
                ],
            ],
        },
    ];
    assert.equal(buildBase({ outline, inset: touching, stem, keycap }), null);
    assert.equal(buildBase({ outline, inset: good, stem: { x: 13.5, y: 0 }, keycap }), null, 'the cutout is not in the inset');
    const flat = { ...keycap, base: { ...keycap.base, plate: keycap.base.height } };
    assert.equal(buildBase({ outline, inset: good, stem, keycap: flat }), null, 'no room under the plate');

    const body = { ...KEYCAP_BODY, keycap: { base: { plate: 12 } } };
    const settings = { ...SETTINGS, base_shape: 'square' };
    const analysis = analyzeArtwork(solidBlue(), settings, body, 'preview');
    assert.deepEqual(analysis.blockers, [FAILED_BLOCKER]);
    assert.equal(analysis.cap.baseBody, null);
    assert.throws(() => buildParts(analysis, settings, body), { message: FAILED_BLOCKER });
});

test('legacy bodies get no base in either layout', async () => {
    const { BODY } = await import('./helpers.js');
    const analysis = analyzeArtwork(logoArtwork(200), SETTINGS, BODY, 'preview');
    for (const layout of ['assembled', 'print']) {
        assert.ok(buildParts(analysis, SETTINGS, BODY, { layout }).every((p) => p.kind === 'colour' || p.kind === 'outline'));
    }
});

function writeJob(image, settings, body) {
    const dir = mkdtempSync(join(tmpdir(), 'clicker-job-'));
    const png = new PNG({ width: image.width, height: image.height });
    png.data = Buffer.from(image.data);
    writeFileSync(join(dir, 'artwork.png'), PNG.sync.write(png));
    writeFileSync(join(dir, 'job.json'), JSON.stringify({ settings, body, title: 'Keycap', folder: 'clicker-1' }));

    return dir;
}

test('build.mjs writes Cap and Base, the base laid out for printing', () => {
    const dir = writeJob(logoArtwork(300), { ...SETTINGS, base_shape: 'trace' }, KEYCAP_BODY);
    const run = spawnSync(process.execPath, [BUILD, dir], { encoding: 'utf8' });

    assert.equal(run.status, 0, run.stderr);
    const model = strFromU8(unzipSync(readFileSync(join(dir, 'clicker.3mf')))['3D/3dmodel.model']);
    const names = [...model.matchAll(/<object [^>]*name="([^"]*)"/g)].map((m) => m[1]);
    assert.deepEqual(names.slice(-2), ['Cap', 'Base']);
    assert.equal(names.filter((n) => n === 'Base').length, 1);

    const zip = unzipSync(readFileSync(join(dir, 'clicker-stl.zip')));
    const files = Object.keys(zip).filter((f) => f.endsWith('.stl'));
    const baseFile = files.find((f) => /base/.test(f));
    const capFile = files.find((f) => /cap/.test(f));
    assert.ok(baseFile, files.join(', '));
    assert.match(baseFile, /\/\d\d-base\.stl$/);
    assert.match(capFile, /\/\d\d-cap\.stl$/);
    assert.ok(
        files.filter((f) => f !== baseFile && f !== capFile).every((f) => /\/\d\d-colour-[0-9a-f]{6}\.stl$/.test(f)),
        files.join(', '),
    );

    const base = parseStl(zip[baseFile]);
    const cap = parseStl(zip[capFile]);
    assert.ok(isClosedManifold(base));
    const [bx0, , bz0, , , bz1] = bounds(base);
    assert.ok(Math.abs(bz0) < EPS && Math.abs(bz1 - HB) < EPS, 'flat on the bed');
    assert.ok(bx0 >= bounds(cap)[3] + SPACING - EPS, 'beside the cap');

    const result = JSON.parse(readFileSync(join(dir, 'result.json'), 'utf8'));
    assert.deepEqual(
        result.parts.slice(-2).map((p) => p.kind),
        ['body', 'base'],
    );
});

test('build.mjs still says no printable colour when only the cap and base are there', () => {
    const dir = writeJob(
        makeImage(20, 20, () => null),
        { ...SETTINGS, base_shape: 'square' },
        KEYCAP_BODY,
    );
    const run = spawnSync(process.execPath, [BUILD, dir], { encoding: 'utf8' });

    assert.equal(run.status, 1);
    assert.match(run.stderr, /no printable colour/);
});
