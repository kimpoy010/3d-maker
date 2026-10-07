# Open-Top Base Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the closed-block base with an open-top base that the cap slides into, with an adjustable insertion depth, and make the Trace base-fit rule accept ordinary logos.

**Architecture:** Same shared plain-JS geometry module. The base is rebuilt from planar faces and wall strips (welded, `verifiedMesh`-checked) around grown outlines of the cap outline; Trace growth reuses the coarse raster mask. Laravel validates one new setting; the editor gets one slider.

**Tech Stack:** Laravel 12, Pest, Vue 3 + TypeScript + Inertia, plain ESM JS tested with `node --test` (`npm run test:clicker`).

**Spec:** `docs/superpowers/specs/2026-10-08-base-socket-redesign-design.md` (binding; read it first), which supersedes the "Base" paragraph of `2026-10-08-keycap-base-hollow-cap-design.md`.

## Global Constraints

- Geometry code in `resources/clicker/` is plain ESM JavaScript with no browser-only or Node-only APIs; dependencies: earcut, fflate, pngjs (pngjs only in `build.mjs` and tests).
- Units are millimetres. Defaults exactly as in the spec table (clearance 0.3, wall 2.0, plate 1.5, under 6.0, cavity 16.2, cutout 14.1, rest_gap 8.0, spacing 10, hex #6B7280); the cap, the hollow cap, colours, background removal and the existing blockers are unchanged.
- Every body shell passes `verifiedMesh` (closed manifold, positive volume) before it can reach an export; a failure is a blocker, never a broken mesh. No overlapping shells.
- Legacy (no `generator`) bodies are untouched; all other existing tests keep passing or are intentionally updated with a note in the report.
- Money path, locking, queue and sweeper behaviour are not touched.
- Do not name third-party toy or figure brands in UI copy, prompts or file names; do not copy any third party's geometry or assets.
- Never run `migrate:fresh`, `config:cache` or `db:wipe`; Pest runs only on in-memory SQLite; never print or commit API keys.
- Create and edit files with the Write/Edit tools, not shell heredocs (including reports and scratch scripts; delete scratch files). Run Pint/Prettier only on files you changed.
- Browser checks: the app is at http://localhost:8000, Vite on 5173 and a queue worker are running; use a throwaway test user (never the owner's, user id 5), deleted afterwards with its clickers, files, ledger rows and sessions; report every process left running; if a kill or move is denied, report it, do not work around it.
- The owner's real test artwork is at `C:\Users\kimca\AppData\Local\Temp\claude\C--laragon-www-3d-maker\bbf82a3c-27f4-4c26-a9ea-01256d1deff3\images\18.png` (opaque white background, a blue monitor with white mountains and the letters ZION, a teal ring and a dot). You may read it in local scratch scripts and in the browser check to reproduce results; never copy it into the repository, tests or fixtures (tests use a synthetic look-alike).
- Commit messages end with the line `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.

---

### Task 1: Open-top base geometry, fit rule and layouts

**Files:**
- Modify: `resources/clicker/keycap.js`, `resources/clicker/trace-outline.js`, `resources/clicker/geometry.js`, `resources/clicker/build.mjs` (only if needed), `resources/clicker/exports.js` (only if needed)
- Test: `resources/clicker/tests/base.test.js` (rewrite for the new base), `resources/clicker/tests/keycap-pipeline.test.js` and any other test that asserted the old base numbers or the flipped print layout (update with a note)

**Interfaces:**
- Consumes: `solids.js` (`wallStrip`, `planarFace`, `weld`, `verifiedMesh`, `pointInPolygons`), `insetOutline`-style analytic helpers in `keycap.js` (add `growOutline(outline, shape, keycap, distance) -> ring` for Square/Circle: rounded-square radius + distance, circle radius + distance, both CCW), `traceOutline(...)` result (add `grow(distance) -> ring`: dilate the coarse mask the outline came from by `distance` mm, trace, simplify 0.03 mm, outer CCW; reuse the same machinery as `cavity()`/`inset()`; the existing `inset()` may stay for the hollow cap).
- Produces: `KEYCAP_DEFAULTS.base` exactly as in the spec (deep-merged by `keycapConfig`; old keys `height`/`gap` ignored); `settings.cap_insertion` read via a helper `capInsertion(settings)` (clamped to 0.5..1, default 0.5); `buildBase({pocket, outline, stem, keycap, insertion}) -> {kind: 'base', name: 'Base', hex, positions, indices}` per the spec "Base geometry" (z 0 at the bottom, upright, open top); `baseFits({cap outline polygons, base outline, stem, keycap}) -> boolean` per the spec "Fit rule" (cutout + 1.6 mm square inside the CAP outline; cavity square inside the BASE outline; no hole/island inside; test corners, edge midpoints and a segment-vs-edge test like the existing helper); `analysis.cap.baseOutline` and `analysis.cap.pocketOutline`; the blocker texts unchanged; `buildParts(analysis, settings, body, {layout})` 'assembled' and 'print' per the spec "Layouts" (assembled base placed so the plate top is at z `-rest_gap`; print layout upright, translated +x by the base outline width + spacing); the base is still built once in `analyzeArtwork` and reused when only heights change, but it now depends on `cap_insertion`, so changing the insertion needs a fresh analysis (document in the report; Task 2 puts it in the editor's analysisKey).

- [ ] **Step 1: Write failing tests** exactly as listed in the spec "Tests" for the Node side (Square, Circle and a synthetic Trace logo; volumes; rays; assembled/print layouts; the owner-like logo at scale 1 in Trace not blocked; a 6 mm blob blocked; `cap_insertion` 0.5 vs 1.0 rim heights; `build.mjs` end to end: exit 0, 3MF objects `Cap` and `Base`, base STL present and closed manifold, base upright at z 0..top and clear of the cap by at least `spacing`). Build the owner-like synthetic logo in `tests/helpers.js` (500x500, opaque white background, a blue rounded rectangle monitor with a stand, white interior shapes, a thick teal elliptical ring around it, a teal dot touching the ring, 4x4 supersampled edges) and run background removal through `prepareArtwork` as the pipeline does.
- [ ] **Step 2: Run** `npm run test:clicker`, see the new failures only.
- [ ] **Step 3: Implement.** Remove the old base builder and its fit rule completely (no dead code); keep `verifiedMesh` use and the blockers.
- [ ] **Step 4: Run** `npm run test:clicker`, all pass. Also run (scratch script, deleted afterwards) the owner's real `18.png` through `analyzeArtwork` + `buildParts` for Trace at scale 1 with hollow off and on and report: blockers, notes, part names and bounds, base bounds. It must not be blocked at scale 1; report the smallest scale at which Trace is blocked. Report the warm wall-clock of `analyzeArtwork + buildParts` for a 500x500 Trace logo (under 1 s).
- [ ] **Step 5: Commit** `feat: open-top switch base that the cap slides into`.

---

### Task 2: Laravel setting, body config and editor slider

**Files:**
- Modify: `app/Http/Requests/SaveClickerRequest.php`, `app/Http/Resources/ClickerResource.php` (only if defaults need it), `app/Models/ClickerBody.php`, `resources/js/lib/clicker.ts`, `resources/js/pages/clickers/Edit.vue`, `README.md`
- Test: `tests/Feature/ClickerHttpTest.php`, `tests/Feature/ClickerBodySeederTest.php`

**Interfaces:**
- Consumes: Task 1 (`settings.cap_insertion`, the new `keycap.base` keys).
- Produces: `settings.cap_insertion` (numeric 0.5 to 1, default 0.5, normalised to a float; missing means 0.5) validated and stored; `ClickerBody::sampleConfig()['keycap']['base']` with the spec numbers (replacing the old block); editor control "Cap depth inside base" (range input 50 to 100 percent, step 5, shown for generator bodies inside the locked fieldset, with the current value as text and a hint "How far the cap sits inside the base at rest."); `cap_insertion` in `ClickerSettings`, defaults in `emptySettings`/`normalise` (0.5), in `analysisKey` (the base depends on it), dirty tracking and the save payload.

- [ ] **Step 1: Write failing Pest tests**: save with 0.5, 0.75, 1 stores the value; 0.4 and 1.1 and "abc" fail on `settings.cap_insertion`; missing becomes 0.5; the seeder config pins the new base numbers (equality with `sampleConfig()` and literal spec values); old designs without the key load with 0.5 and are not dirty (props test).
- [ ] **Step 2: Run** `vendor/bin/pest --filter=Clicker`, see failures.
- [ ] **Step 3: Implement** PHP and TS/Vue. README: describe the open-top base and the slider, that the cap slides in at least half, that a solid cap gives less key travel than a hollow cap, that `keycap.base.rest_gap`, `under`, `cavity`, `clearance` and `cutout` are provisional and where they live. Update the dev body row through tinker: replace the `keycap.base` block with the new one (keep other keys; report keys before and after; re-seeding does not overwrite).
- [ ] **Step 4: Verify**: `npx eslint` on changed files, `npx vue-tsc --noEmit` (2 baseline TS2688 only), `npm run build`, `npm run test:clicker`, full `vendor/bin/pest`, Pint on changed PHP files only.
- [ ] **Step 5: Browser check** (throwaway user, the owner-like logo you generate AND the owner's `18.png` uploaded through the page): (a) Trace with the owner's logo at scale 100 percent is not blocked, the preview shows the cap sitting partly inside an open-top base slightly larger than the cap; (b) Circle and Square likewise; (c) moving the slider from 50 to 100 percent raises the base rim (more of the cap inside) and does not reset the camera; (d) a very small Trace scale shows the red message; (e) unlock one Trace design (hollow on) and one Square design (hollow off), wait for Ready via the real queue, unzip the 3MF: `Cap` and `Base` objects, base upright beside the cap, the pocket and cutout vertices where expected, STL zip contains NN-cap.stl and NN-base.stl; report file sizes. Screenshot and describe every case honestly (the owner's main acceptance check). Clean up and verify zero leftovers.
- [ ] **Step 6: Commit** `feat: cap insertion setting, new base config and slider`.

---

### Task 3: Closed bottom

**Files:**
- Modify: `resources/clicker/keycap.js`, `resources/clicker/geometry.js` (only if needed), `app/Models/ClickerBody.php` (sample config), `tests/Feature/ClickerBodySeederTest.php`, `README.md`
- Test: `resources/clicker/tests/base.test.js` (and any test that asserted the open bottom or `under` 6.0)

**Interfaces:**
- Consumes: Task 1 (`buildBase`, `baseFits`, `placeBase`, layouts).
- Produces: `KEYCAP_DEFAULTS.base` gains `floor: 1.5` and `under` becomes `7.0` (the enclosed switch pocket is `under - floor` = 5.5 mm tall); `buildBase` per the amended spec "Base geometry": floor z 0..floor solid over the whole base outline; the square pocket (`cavity` x `cavity`, centred on the stem) enclosed from `floor` to `under`, ceiling = the plate underside (with the cutout hole); the bottom face at z 0 is the whole base outline with no hole; a pocket floor face at `floor` (the cavity square, facing up); all other faces as before; still ONE closed manifold shell through `verifiedMesh`; `buildBase` returns null (blocker) when `floor <= 0` or `under <= floor`; old rows without `floor` use the default; rows that still say `under: 6.0` keep working (pocket 4.5 tall).

- [ ] **Step 1: Write failing tests**: the bottom is closed (a vertical ray through any point inside the base outline, including through the stem centre and through the cavity square, enters material at z 0 facing up... i.e. the first intersection from below is at z = 0; the ray through the cutout column now hits material only in [0, floor] and nothing above: pocket empty, plate hole empty); volume = `base outline area x top - pocket area x (top - under - plate) - cavity² x (under - floor) - cutout² x plate` within 1 percent for Square, Circle and a Trace logo; closed manifold; assembled layout unchanged except the lower z (base z 0 maps to `-(rest_gap + under + plate)` as before: check the new numbers); print layout z 0..top; fit rule and blockers unchanged; `floor` missing in the config uses 1.5; `under <= floor` gives the "could not be built" blocker; PHP seeder test pins `under` 7.0 and `floor` 1.5.
- [ ] **Step 2: Run** `npm run test:clicker`, see the new failures only.
- [ ] **Step 3: Implement**; update `ClickerBody::sampleConfig()` (floor 1.5, under 7.0); README: closed bottom, switch pins must be trimmed (or use a pinless switch), the enclosed pocket.
- [ ] **Step 4: Dev body row:** through tinker REPLACE only `keycap.base` on the active "Cherry MX keycap" row with the new block (keep other keys; report keys before and after).
- [ ] **Step 5: Verify:** `npm run test:clicker`, full `vendor/bin/pest`, `npx vue-tsc --noEmit` (2 baseline TS2688 only), `npm run build`, eslint/Prettier on changed JS, Pint on changed PHP.
- [ ] **Step 6: Browser check** (throwaway user, owner's real `18.png` uploaded through the page): the Trace preview shows a closed-bottom base (orbit under it: solid floor, no hole), the cap still sits partly inside; unlock the design (hollow ON), unzip the 3MF: `Base` has a solid z 0 face (no opening on the underside: check that vertices at z 0 form the full outline with no inner ring) and the cutout still at +/-7.05; clean up and verify zero leftovers.
- [ ] **Step 7: Commit** `feat: close the bottom of the switch base`.

---

### Task 4: Docs and full verification

**Files:** `README.md`, the two spec files' status lines

- [ ] **Step 1:** README Clicker section: replace any text describing the closed base, the flipped print layout and the old numbers; document the open-top base, print orientation (cap upright, base upright open top, no supports for the base; hollow cap needs supports under its roof), the slider, the provisional numbers and the launch checklist ("print one cap and one base, fit a real Cherry MX switch, set `rest_gap`/`under`/`cavity`/`clearance`").
- [ ] **Step 2:** Mark `2026-10-08-base-socket-redesign-design.md` Status Implemented and note in `2026-10-08-keycap-base-hollow-cap-design.md` that its Base paragraph is superseded.
- [ ] **Step 3: Full verification:** `vendor/bin/pest`, `npm run test:clicker`, `npx vue-tsc --noEmit` (baseline only), eslint on changed JS/TS/Vue files, `npm run build`. Report counts.
- [ ] **Step 4: Commit** `docs: document the open-top base`.
