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
    assert.deepEqual(
        parts.map((p) => p.hex),
        ['#1D4ED8', '#DC2626', '#FFFFFF'],
    );
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

    assert.ok(
        analysis.notes.some((n) => n.includes('thinner than 0.8 mm')),
        analysis.notes.join(' | '),
    );
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
