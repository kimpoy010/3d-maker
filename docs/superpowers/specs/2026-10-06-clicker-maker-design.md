# Clicker maker: a tool for designing fidget clickers

Date: 2026-10-06
Status: Implemented

## 1. Context and goal

The app turns photos into 3D figurines. This adds a second, separate tool: users upload flat artwork and get a layered, multi-colour **fidget clicker** they can print themselves. The reference is a browser tool that separates artwork into colour regions, raises each region to its own height, fits the result on a clicker body, and exports print files.

The tool needs no Meshy or OpenAI call, so it costs nothing per use. Revenue is a one-time unlock to download the print files.

Decisions made with the owner:

| Question | Decision |
|---|---|
| Input and output | The user's own flat artwork becomes a colour-layered clicker. No AI-generated art in v1. |
| Who prints | The customer downloads print files. Ordering a printed, shipped clicker comes later, with the figurine print option and real payments. |
| Export | A 3MF (one named part per colour, colours attached) plus a zip of one STL per colour. |
| Clicker body | The owner supplies tested body models. A clearly labelled placeholder body is used for development and tests until they arrive. |
| Price | Free to design and save. ₱50 once per design to download the files. |
| Architecture | Hybrid: live design and preview in the browser, final files built on the server after the unlock, from one shared geometry module. |
| Edits after paying | A paid design is locked. "Duplicate to edit" creates a new draft that needs its own unlock. |

## 2. Scope

In v1:

- A "Clicker maker" page in the sidebar: a list of the user's saved designs and a single-page editor.
- Editor steps: body, artwork, colours, heights (with optional outline), live 3D preview, save, unlock and download.
- Saved designs per account (at most 20).
- Print notes for details that are too thin or too small to print.
- An unlock charged in the wallet, with automatic refund if the build fails.

Not in v1: printing and shipping orders, AI-generated artwork, press-the-button animation, several artworks in one design, vector-exact SVG import, and a public gallery.

## 3. Data model

**`clicker_bodies`** (seeded from `resources/clicker/bodies/<slug>/`): `id`, `slug`, `name`, `description`, `preview_image`, `model_files` (JSON list of paths), `config` (JSON, below), `active`.

`config` fields (all in millimetres): `cap.shape` (`rect`, `rounded`, `circle`), `cap.width`, `cap.height`, `cap.corner_radius`, `cap.top_z` (the height of the cap surface), `artwork_area` (x, y, width, height inside the cap), `base_thickness`, `max_layer_height`, `min_feature` (smallest printable detail, for example 0.8), and `switch` (a text note, for example "Kailh 5-pin", shown to the user).

**`clickers`**: `id`, `user_id`, `clicker_body_id`, `name`, `artwork_path` (private PNG), `settings` (JSON, below), `thumbnail_path`, `status` (`draft`, `building`, `ready`, `failed`), `build_error`, `threemf_path`, `stl_zip_path`, `downloads_unlocked_at`, `unlock_attempts` (how many times the user has paid to unlock it), `build_started_at` (used by the sweeper), timestamps.

`settings`: `colors` (list of `{hex, height_mm, enabled}`, 1 to 8 entries), `color_count`, `scale` (0.1 to 1.0 of the artwork area), `offset_x`, `offset_y` (millimetres), `outline_mm` (0 to 2, an outline band between colours), `outline_hex`.

**`credit_ledger`**: add nullable `clicker_id` and `clicker_attempt` with a unique index on (`clicker_id`, `reason`, `clicker_attempt`). New reasons: `clicker_download` and `clicker_refund`. Each unlock is one numbered attempt: one charge per attempt, and at most one refund per charge. A failed build refunds its attempt, and unlocking again is attempt 2, so a retry after a refund does not collide with the first charge.

**Config** (`config/credits.php` and `config/clicker.php`): `CREDITS_CLICKER_COST` (default 50), `CLICKER_MAX_PER_USER` (20), upload limits (10 MB, 256 to 4096 px), `CLICKER_NODE_BINARY` (default `node`), `CLICKER_BUILD_TIMEOUT` (90 s).

## 4. Backend

- **`ClickerController`**: index, create, show/edit, store, update, duplicate, destroy. A locked (unlocked-for-download) design rejects updates.
- **`ClickerArtworkController`**: upload. The file is re-encoded with GD to a PNG, capped at 4096 px on a side, and stored privately. Only real raster images pass. The server never parses SVG.
- **`ClickerService`**: `save`, `duplicate`, and `unlock`. `unlock` locks the clicker row, then the user row (the same order rule as the other money paths), charges, sets `downloads_unlocked_at`, sets `status=building`, and dispatches `BuildClickerFiles` only after the transaction commits. It is idempotent: unlocking twice charges once.
- **`BuildClickerFiles` job**: writes a job folder with the artwork, settings and body config, runs `node resources/clicker/build.mjs <job-folder>` as a process with an argument array (no shell string), a timeout, and a memory cap, then records the 3MF and zip paths and sets `status=ready`. On failure it sets `status=failed`, clears `downloads_unlocked_at`, and refunds the latest attempt in the same transaction as the status change, then logs without personal data. The user can unlock again, which is a new numbered attempt. A `WithoutOverlapping` lock covers one build per design, and the timeout, lock expiry and queue `retry_after` keep the invariant already used elsewhere (job timeout under lock expiry under `retry_after`).
- **Sweeper**: the existing five-minute sweep also fails and refunds any clicker in `building` longer than the build deadline plus a grace period.
- **`ClickerFileController`**: owner-only. Thumbnail and artwork are served inline. The 3MF and the zip are downloads and require `downloads_unlocked_at` and `status=ready`. All responses send `nosniff` and a sandbox CSP, and personal images use `private, no-cache`.
- **Pricing messages** use `App\Support\Money::peso()`, for example "Unlocking downloads costs ₱50 and you have ₱20."

## 5. Shared geometry module

`resources/clicker/geometry.js` is plain ESM with no DOM dependency. The pipeline is split in two so the editor stays responsive: `analyzeArtwork` (slow: palette snapping, clean-up, tracing) and `buildParts` (fast: extrusion), so a height change only re-runs the fast half. The editor imports the module through Vite for the live preview, and `build.mjs` imports the same file in Node, so the preview and the printed files match.

Input: decoded RGBA pixels, `settings`, the body `config`, and a `quality` (`preview` at up to 1024 px, `final` at up to 2048 px). Output: a list of parts `{name, hex, positions, indices}` and a list of print notes.

Pipeline:

1. Treat pixels with alpha below 128 as background.
2. Suggest `color_count` colours (median cut refined by a few k-means passes, deterministic) when new artwork is loaded. After that the palette in the settings is the only source of truth: snap each pixel to the nearest enabled colour of the user's palette. Disabled colours become background.
3. Merge regions smaller than `min_feature` squared into their biggest neighbour and record a print note. Details thinner than `min_feature` are not removed; they are checked on a small copy of the grid (so the live preview stays fast) and reported as print notes.
4. Scale and position the artwork inside `artwork_area`, then mask to the cap shape so nothing overhangs the body.
5. Trace each colour mask into polygons with holes by following pixel edges (4-connected regions, collinear points merged, no smoothing), converted to millimetres. The outlines are exact, so they cannot self-intersect.
6. Extrude each colour from `cap.top_z` to `cap.top_z + height_mm`. Colours do not overlap, so each part is a closed solid and no 3D boolean operation is needed. An optional outline band is its own part.

Node dependencies: `pngjs` (read the PNG) and `fflate` (write the zip and 3MF containers). The body mesh is parsed from STL by a small reader in the module.

## 6. Exports

**3MF:** a zip with `[Content_Types].xml`, `_rels/.rels` and `3D/3dmodel.model` (unit millimetre). The model has one `basematerials` resource for the colours, one `object` per colour part and one for the body, each named (for example "Colour 2 #F5A742"), and a `build` with all items. It opens as a multi-part project in Creality Print, Bambu Studio and PrusaSlicer. The slicer may still ask the user to map each colour to a filament.

**STL zip:** `clicker-<id>/colour-<n>-<hex>.stl` (binary STL) for each colour part, `body.stl`, and `README.txt` with the part list and the print notes.

## 7. Editor (front end)

A single Inertia page, `clickers/Edit.vue`, with a left step panel and a large preview that reuses `ModelViewer` (lighting presets, rotate, clay or textured). Colour changes and height changes recompute the preview locally. Steps: body, artwork (upload, scale, position), colours (count, palette edits, merge, disable), heights and outline, and a final step with the print notes, **Save**, **Unlock downloads (₱50)** and the two download buttons. SVG files are rasterised to a 2048 px PNG in the browser before upload. A locked design shows read-only controls and **Duplicate to edit**.

## 8. Errors

Plain messages only. Bad uploads name the problem (not an image, too small, too large). An unaffordable unlock states both amounts. A failed build says "We couldn't build your files. You were refunded." and offers a retry. Missing Node, a build timeout and a crash all refund and log a critical line for the operator.

## 9. Testing

- Pest feature tests: ownership and policies, validation, the 20-design limit, charging once, double-click safety, insufficient balance, gated downloads, refund on a failed build (with a fake builder), the sweeper, and a locked design rejecting edits.
- `node --test` unit tests for the geometry: palette snapping, speck removal, traced area against pixel area, every part closed and manifold (each edge shared by exactly two triangles), STL byte size, and the 3MF structure (entries present, XML well-formed, vertex indices in range).
- One end-to-end test that runs the real Node script on a tiny PNG, skipped when `node` is not installed.
- A real browser check of the editor, with a screenshot, before calling the UI done.
- Tests keep running only on in-memory SQLite (the guard in `tests/TestCase.php`).

## 10. Risks and open items

- **A real body is needed to ship.** The owner supplies the STL or 3MF files, the switch size, and the cap's artwork area and shape. Until then a labelled placeholder (rounded slab) is used for development.
- **Node must be installed on the server** (`npm ci` on deploy); documented in the README.
- **The browser preview can be copied.** As with the figurine GLB, the paid value is the print-ready multi-colour files, built and gated on the server.
- **Slicer colour mapping** for 3MF cannot be removed.
- A complex SVG may be slow to rasterise on a weak phone.

## 11. Build order

1. The geometry module and its tests.
2. Backend: tables, upload, service, job, files, sweeper.
3. The editor page.
4. Print notes and polish.
5. README and deploy notes.
