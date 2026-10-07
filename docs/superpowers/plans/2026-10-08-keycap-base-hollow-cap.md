# Keycap Base, Hollow Cap and Background Removal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the switch base as a second printable piece, a Hollow cap option, and automatic background removal, to the Cherry MX keycap clicker.

**Architecture:** The shared plain-JS geometry module (`resources/clicker/`) builds all shells from planar faces and wall strips welded by exact coordinates, so every body is one closed manifold. Square/Circle insets are analytic; Trace insets come from raster erosion on the existing coarse grid. Laravel only validates and stores two booleans and passes config through.

**Tech Stack:** Laravel 12, Pest, Vue 3 + TypeScript + Inertia, plain ESM JS tested with `node --test` (`npm run test:clicker`).

**Spec:** `docs/superpowers/specs/2026-10-08-keycap-base-hollow-cap-design.md` (binding; read it first). Earlier: `2026-10-07-cherry-mx-keycap-body-design.md`.

## Global Constraints

- Geometry code in `resources/clicker/` is plain ESM JavaScript, no browser-only or Node-only APIs (it runs in both); dependencies allowed: earcut, fflate, pngjs (pngjs only in `build.mjs` and tests).
- Units are millimetres. Defaults from the spec: hollow {wall 1.6, plate 2.0, boss_radius 3.6}; base {height 12, plate 1.5, wall 2.0, cutout 14.1, gap 4.0, spacing 10}; existing keycap defaults unchanged (size 30, rim 2, height 8, socket_depth 4.5, stem 4.2/1.35/1.2).
- Every body shell passes `isClosedManifold`; no overlapping shells, no coplanar internal faces.
- The solid cap (`hollow_cap` false) and Square/Circle/Trace outlines stay byte-for-byte equivalent to today's output apart from part names (`Body` becomes `Cap` only where the spec says so); legacy (no `generator`) bodies are untouched; all existing tests keep passing or are intentionally updated with a note in the report.
- Money path, locking, queue and sweeper behaviour are not touched.
- Do not name third-party toy or figure brands in UI copy, prompts or file names; do not copy any third party's geometry or assets.
- Never run `migrate:fresh`, `config:cache` or `db:wipe`; Pest runs only on in-memory SQLite; never print or commit API keys.
- Create and edit files with the Write/Edit tools, not shell heredocs (including reports and scratch scripts). Run Pint/Prettier only on files you changed.
- Browser checks: the app is at http://localhost:8000 (not http://localhost), Vite on 5173 and a queue worker are running; use a throwaway test user (never the owner's, user id 5), deleted afterwards with its clickers, files, ledger rows and sessions; report every process left running; if a kill or move is denied, report it, do not work around it.
- Commit messages end with the line `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.

---

### Task 1: Background removal

**Files:**
- Modify: `resources/clicker/raster.js`, `resources/clicker/geometry.js`
- Test: `resources/clicker/tests/background.test.js`

**Interfaces:**
- Produces: `removeBackground(image, tolerance = 48) -> {data, width, height}` (new RGBA array, input untouched; algorithm exactly as the spec's "Background removal" steps 1-4) in `raster.js`; `prepareArtwork(image, settings) -> image` in `geometry.js` (returns `removeBackground(image)` when `settings.remove_background !== false`, else `image`); `analyzeArtwork` calls `prepareArtwork` first, for every body type. `suggestPalette` is unchanged: callers pass it `prepareArtwork(image, settings)`; export `prepareArtwork` from `geometry.js` so `resources/js/lib/clicker.ts` can use it (Task 5 wires the editor).
- Consumes: existing `downscale`, `buildLabels`, `suggestPalette`.

- [ ] **Step 1: Write failing tests** (`node:test`, helpers from `tests/helpers.js`): a 300x300 white-background logo (blue disc with an inner white letter-shaped hole region not connected to the border) becomes transparent outside the disc and keeps the inner white; a coloured (non-white) uniform border works; a noisy border (random pixels) leaves the image unchanged; an image with real transparency (>2 percent alpha < 128) is returned unchanged (same values); tolerance: a pale-grey gradient border within distance 48 is removed, a darker border ring is not; `removeBackground` does not mutate its input; `analyzeArtwork` with a white-background fixture and `base_shape: 'trace'` on a generator body yields an outline that hugs the disc (area within 20 percent of disc area plus rim) rather than a rectangle; with `remove_background: false` it yields the rectangle; `suggestPalette(prepareArtwork(...), 3)` does not contain white for the white-background logo.
- [ ] **Step 2: Run** `node --test resources/clicker/tests/background.test.js`, see failures.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `npm run test:clicker`, all pass (93 before).
- [ ] **Step 5: Commit** `feat: remove a plain background from opaque artwork`.

---

### Task 2: Planar-face shell builders, insets and the hollow cap

**Files:**
- Create: `resources/clicker/solids.js`
- Modify: `resources/clicker/keycap.js`, `resources/clicker/trace-outline.js`, `resources/clicker/geometry.js`
- Test: `resources/clicker/tests/solids.test.js`, `resources/clicker/tests/hollow.test.js`

**Interfaces:**
- Produces in `solids.js`: `wallStrip(ring, z0, z1, facing)` (`facing` 'out' or 'in': normals point away from / into the area the ring encloses; rings may be CCW or CW, derive the winding), `planarFace(polygons, z, up)` (`polygons` = `[{outer, holes}]`, triangulated with earcut, normals up or down regardless of the input ring winding), `weld(meshes, epsilon = 1e-6) -> {positions: Float32Array, indices: Uint32Array}` (concatenates meshes and merges vertices with identical coordinates, drops degenerate triangles), `pointInPolygons(point, polygons) -> boolean`.
- Produces in `keycap.js`: `insetOutline(outline, shape, keycap, distance)` for the analytic cases (`'square'`, `'circle'`) returning `[{outer, holes: []}]`; `buildHollowCapBody({outline, cavity, stem, keycap}) -> {kind: 'body', name: 'Cap', hex: '#9CA3AF', positions, indices}` where `cavity` is `[{outer, holes}]` (outer CCW, holes CW; the holes are solid islands) exactly as described in the spec "Hollow cap" paragraph (the island that contains the stem gets the cross hole; if no island contains the stem the cross is a hole of the bottom outline face), built with `wallStrip`/`planarFace`/`weld`; `hollowCavity({shape, outline, stem, keycap, trace})` returning the cavity polygons for Square/Circle (inset ring with a boss-disc island, 48 segments) and for Trace (via `trace.cavity(wall, bossRadius)` below), or `[]` when nothing fits.
- Produces in `trace-outline.js`: `traceOutline(...)` additionally returns `cavity(wall, bossRadius) -> [{outer, holes}]` in mm, derived from the same coarse mask (erode by `wall`, remove the boss disc around the stem, trace with `traceLabel` + `toMm`, simplify 0.03 mm, `ensureCcw` outer / CW holes, drop pieces under 4 mm²) and `inset(distance) -> [{outer, holes}]` (same, without the disc), for Task 3.
- `analyzeArtwork`: for generator bodies with `settings.hollow_cap === true` adds `analysis.cap.cavity` (from `hollowCavity`) and, when it is empty, a note "The shape is too small for a hollow cap, so the cap is solid."; `buildParts` uses `buildHollowCapBody` when `analysis.cap.cavity` is non-empty, otherwise `buildKeycapBody` (name the solid cap part `Cap` too; update tests).

- [ ] **Step 1: Write failing tests.** `solids.test.js`: `planarFace` of a square with a hole has area = outer - hole and faces the requested direction (check the signed volume of a closed prism built from two faces and four wall strips for both `facing` values); `weld` merges coincident vertices; a prism from faces + strips welded passes `isClosedManifold` with volume = area x height. `hollow.test.js` (square, circle, and Trace on a synthetic logo): the hollow body passes `isClosedManifold`; its volume equals `outline area x H - cavity area x (H - plate) - cross area x socket_depth` (cavity area = inset polygon minus boss disc) within 1 percent; a vertical ray through the stem hits material only from socket_depth to H; a vertical ray through a cavity point hits material only from H - plate to H; bounds z 0..H; `hollow_cap: false` still yields the solid cap with the same volume as before; a thin Trace shape where nothing fits falls back to the solid cap with the note.
- [ ] **Step 2: Run** the two files, see failures.
- [ ] **Step 3: Implement** (reuse `mesh.js` `triangulate` where it helps; do not change `extrudePolygons` output).
- [ ] **Step 4: Run** `npm run test:clicker`, all pass.
- [ ] **Step 5: Commit** `feat: hollow keycap cap built from welded planar faces`.

---

### Task 3: The switch base, layouts and exports

**Files:**
- Modify: `resources/clicker/keycap.js`, `resources/clicker/geometry.js`, `resources/clicker/build.mjs`, `resources/clicker/exports.js` (only if part kinds need handling)
- Test: `resources/clicker/tests/base.test.js`

**Interfaces:**
- Consumes: Task 2 (`solids.js`, `insetOutline`, `trace.inset`).
- Produces: `buildBase({outline, inset, stem, keycap}) -> {kind: 'base', name: 'Base', hex, positions, indices}` per the spec "Base" paragraph (`inset` = `[{outer, holes}]`); `baseFits({inset, stem, keycap}) -> boolean` (cutout square expanded by 0.8 mm inside the inset region; test the square's corners and edge midpoints against the polygons, plus no inset hole/island touching the square); `analysis.cap.baseInset` for generator bodies (analytic for Square/Circle, `trace.inset(wall)` for Trace); a blocker with the spec's exact message when `baseFits` is false; `buildParts(analysis, settings, body, {layout = 'assembled'} = {})` as in the spec "Layouts" (base appended last; cap part named `Cap`; for `'print'` the base is rotated and translated and the printed base's bounding box clears the cap's by at least `spacing`).
- `build.mjs` passes `{layout: 'print'}`; the "no printable colour" check ignores `body` and `base` parts.

- [ ] **Step 1: Write failing tests** for Square, Circle and Trace (synthetic logo): the base is closed manifold; volume = `outline area x height - inset area x (height - plate) - cutout² x plate` within 1 percent; a vertical ray through the stem passes only through ... (hits nothing through the cutout column from z 0 up to the top: no material at the stem x,y at any z); a ray through a wall point hits 0..height; the `assembled` layout has base z in [-(gap + height), -gap] and cap/colours unchanged; the `print` layout has the base flipped (plate at z 0..plate), x-shifted clear of the cap, and still manifold with the same volume; a Trace shape too small for the cutout produces the exact blocker message and `buildParts` throws; `build.mjs` end to end (follow `tests/keycap-pipeline.test.js`): exit 0, the 3MF has objects named `Cap` and `Base`, the STL zip contains a base STL file name containing `base`.
- [ ] **Step 2: Run**, see failures only for the new tests.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `npm run test:clicker`, all pass. Report the wall-clock of `analyzeArtwork + buildParts` for a 500x500 Trace logo at 'preview' and 'final' (must stay under 1 second warm).
- [ ] **Step 5: Commit** `feat: switch base piece and print layout for the keycap clicker`.

---

### Task 4: Laravel side

**Files:**
- Modify: `app/Http/Requests/SaveClickerRequest.php`, `app/Models/ClickerBody.php` (sample config), `database/seeders/ClickerBodySeeder.php` (only if needed), `README.md`
- Test: `tests/Feature/ClickerHttpTest.php`, `tests/Feature/ClickerBodySeederTest.php`, `tests/Feature/NodeClickerBuilderTest.php`

**Interfaces:**
- Produces: `settings.hollow_cap` (boolean, default false) and `settings.remove_background` (boolean, default true) validated, normalised (missing means the defaults; non-boolean strings like "yes" fail validation; accept true/false/1/0) and stored; `ClickerBody::sampleConfig()['keycap']` gains the `hollow` and `base` blocks with the spec defaults (hex `#6B7280`).
- Consumes: the JS keys in the spec ("Parts and parameters").

- [ ] **Step 1: Write failing Pest tests**: save with each boolean combination stores them; missing keys become the defaults; `hollow_cap = 'maybe'` fails on `settings.hollow_cap`; the seeded body's config pins the new numbers (assert equality with `sampleConfig()` and the literal spec values); `NodeClickerBuilder` job.json carries the config; an old saved design without the keys still loads (Inertia props contain the defaults) and the controller test for `clicker.settings`.
- [ ] **Step 2: Run** `vendor/bin/pest --filter=Clicker` (in-memory SQLite only), see failures.
- [ ] **Step 3: Implement.** README: add the base, hollow cap and background removal to the Clicker section (print orientation: the base prints plate-down, the hollow cap needs supports for the cavity roof; numbers are tuneable in the body config and a re-seed does not overwrite an existing body).
- [ ] **Step 4: Run** the full `vendor/bin/pest`; Pint on changed PHP files only.
- [ ] **Step 5: Commit** `feat: hollow cap and background settings, base config`. Then update the dev body: through tinker, merge the new `hollow` and `base` blocks into the existing active "Cherry MX keycap" row's config (keep its other values); report the before/after config keys.

---

### Task 5: Editor: toggles, assembled preview, browser verification

**Files:**
- Modify: `resources/js/lib/clicker.ts`, `resources/js/pages/clickers/Edit.vue`, `resources/js/components/ModelViewer.vue` (only if needed)

**Interfaces:**
- Consumes: Tasks 1-4. `ClickerSettings` gains `hollow_cap: boolean` and `remove_background: boolean`; `PartKind` gains `'base'`; `prepareArtwork` export from the geometry module.

- [ ] **Step 1: Types and wrapper.** Add `prepareArtwork(image, settings)`; the palette suggestion (`paletteFor`) and any "has transparency" logic use the prepared image.
- [ ] **Step 2: Edit.vue.** Defaults `hollow_cap: false`, `remove_background: true` in `emptySettings` and `normalise` (old designs); two labelled switches ("Remove background" with a one-line hint, "Hollow cap" with a hint "Needs supports when printed"), shown for generator bodies under the Base shape picker, inside the locked `fieldset`; both re-run the slow analysis (they are in `analysisKey`); both tracked by dirty/snapshot; sent on save; the preview renders `assembled` parts (the base below the cap with the gap) in a grey that differs from the cap; the camera framing keys on the cap body, not the base (the base must not make the view jump when toggling); the blocker/red box and Unlock gating stay as they are.
- [ ] **Step 3: Verify** `npx eslint` on changed files, `npx vue-tsc --noEmit` (2 baseline TS2688 only), `npm run build`, `npm run test:clicker`, `vendor/bin/pest --filter=Clicker`.
- [ ] **Step 4: Browser check** (throwaway user): upload a white-background JPG-like PNG logo you generate; Trace follows the logo outline (not a rectangle); toggling Remove background off gives the rectangle; Hollow cap on shows a hollow cap (look from below by orbiting) and Unlock still works; the assembled preview shows cap above base; unlock one Trace design with Hollow cap off and one Circle design with Hollow cap on to Ready; unzip the 3MF and confirm objects `Cap` and `Base`, the base flipped beside the cap, the cutout vertices (±7.05 mm around the stem), and the STL zip contains the base STL. Screenshot each case and describe what you see. Clean up as in the global constraints.
- [ ] **Step 5: Commit** `feat: hollow cap and background switches with an assembled preview`.

---

### Task 6: Docs and full verification

**Files:** `README.md`, the spec files (set status to Implemented)

- [ ] **Step 1:** README Clicker section: the two-piece output (cap + base), print orientation per piece, hollow cap trade-offs, background removal behaviour and its limits (needs a plain background), the guess-numbers list and where to change them, and launch checklist items "print one cap and one base and fit a real switch; adjust `keycap.base` / `keycap.hollow` / `keycap.stem` in the body config".
- [ ] **Step 2:** Mark `2026-10-08-keycap-base-hollow-cap-design.md` Status Implemented.
- [ ] **Step 3: Full verification:** `vendor/bin/pest`, `npm run test:clicker`, `npx vue-tsc --noEmit` (baseline only), eslint on changed JS/TS/Vue files, `npm run build`. Report counts.
- [ ] **Step 4: Commit** `docs: document the base, hollow cap and background removal`.
