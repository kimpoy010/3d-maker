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
