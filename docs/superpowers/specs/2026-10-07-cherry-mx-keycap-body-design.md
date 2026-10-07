# Cherry MX keycap body and base shapes: design

Status: approved by the owner in chat on 2026-10-07 ("go, use those defaults"). Extends `2026-10-06-clicker-maker-design.md`.

## Why

The first Clicker Maker release shipped a placeholder slab, so the preview and the files do not look like a clicker. This adds a generated, Cherry MX compatible keycap body and a Base Shape choice: **Square**, **Circle** or **Trace**. The owner's reference is another vendor's generator; nothing of theirs is copied, the stem follows the public Cherry MX dimensions.

## What the user sees

- Step "1. Clicker body" stays; the seeded body becomes **Cherry MX keycap**.
- A new "Base shape" picker with three choices (Square, Circle, Trace) is shown for keycap bodies. Default Square. It is saved with the design.
- The preview shows the real keycap (grey body, coloured artwork raised on top). Downloads contain the colour parts plus a "Body" part.
- If a Trace silhouette is too thin to hold the switch stem, the editor says so in red and Unlock is disabled; a build for such a design fails and refunds.

## Geometry (shared JS module, runs in the browser and in Node)

All sizes are millimetres; defaults live in the body config under `keycap`:

| key | default | meaning |
|---|---|---|
| `size` | 30 | Square side, circle diameter, and the fit box for Trace artwork |
| `rim` | 2 | Square/Circle: border kept free around the artwork. Trace: padding added around the silhouette |
| `corner_radius` | 4 | Square corner radius |
| `height` | 8 | Body height; artwork is raised from here upwards (`top_z` = `height`) |
| `socket_depth` | 4.5 | Depth of the cross socket measured from the underside |
| `stem.span` | 4.2 | Length of each cross arm (hole) |
| `stem.arm` | 1.35 | Width of each cross arm (hole) |
| `stem.wall` | 1.2 | Minimum material between the socket and the outline |

The body config also carries `generator: "mx_keycap"`. Bodies without a `generator` keep the existing behaviour (static STL files, cap mask, grey slab proxy).

**Body mesh.** A solid keycap printed upright, no supports: the outline extruded from z 0 to `height`, with a cross-shaped socket cut from z 0 to `socket_depth`. Built as two closed, manifold shells that overlap by 0.4 mm (slicers union them): shell A = outline minus the cross, z 0 to `socket_depth + 0.4`; shell B = the plain outline, z `socket_depth` to `height`. Each shell passes `isClosedManifold`. Part kind `body`, name `Body`, hex `#9CA3AF`.

**Square / Circle.** Outline = `capPolygon` of a rounded square (`size`, `corner_radius`) or a circle (`size` diameter, 72 segments). Artwork area = `size - 2*rim` square, centred; the existing cap mask, speck cleanup, tracing and extrusion are unchanged. Stem at (0, 0).

**Trace.** Artwork fits a `size` x `size` box times the user's scale and offsets. The outline is computed on a separate coarse grid (at most 512 px on the longest side, at most 0.08 mm per pixel coarser than the working grid, never finer):
1. Silhouette = pixels with alpha >= 128 (an opaque JPG gives a rectangle).
2. Close small gaps with a disc of 1.0 mm radius; if more than one component remains, retry with 2.5, 5 and 8 mm; if still more than one, use the convex hull of the silhouette and add a print note.
3. Fill holes, dilate by `rim` (disc), trace the outer ring, simplify (Douglas-Peucker, 0.03 mm), make it counter-clockwise.
4. The stem sits at the pole of inaccessibility (the point farthest from the outline). If the distance there is below `span/2 * hypot(1, arm/span)` + `stem.wall` (about 3.3 mm) the design has a **blocker**: "This shape is too thin to hold the switch stem. Make the artwork larger or choose Square or Circle."
No cap mask is applied for Trace (the artwork is inside its own silhouette).

**API.** `analyzeArtwork` returns, for generator bodies, `analysis.cap = {outline, stem}` and `analysis.blockers` (string list; empty when fine). `buildParts` throws `Error(blockers[0])` if there are blockers, and otherwise returns the colour parts, the optional outline part and the body part. `build.mjs` already exits 1 on a throw. The editor shows blockers in red and disables Unlock. `bodyProxyPart` stays for legacy bodies only.

## Backend

- `settings.base_shape` in `square | circle | trace`, default `square`, validated and normalised in `SaveClickerRequest` (missing becomes `square`, anything else is a validation error). Saved designs without it behave as `square`.
- `ClickerBodySeeder` seeds "Cherry MX keycap" (active) and no longer seeds the placeholder slab; the placeholder files and factory stay for tests.
- `NodeClickerBuilder` passes the body config through; for a body with `generator` it adds no body files (the part comes from the geometry module).
- Existing money-path, locking and sweeper behaviour is untouched.

## Tests

Node: EDT / morphology / hull / simplify / pole unit tests; the body mesh is closed manifold for all three shapes, has the expected volume (outline area x height minus socket volume, within 2 percent) and the socket has the configured span and arm width; Trace on a synthetic logo (ring plus dot plus bar, soft edges) yields one outline containing the artwork and a stem inside it; a thin line yields a blocker; `build.mjs` end to end for each shape. PHP: request validation, seeder, builder job file for a generator body. Browser: all three shapes in the editor, a real build and 3MF inspection.

## Not in this release

Hollow keycap shells, multiple switch types, per-shape size controls, recessed artwork, and any change to pricing or the unlock flow. The stem clearances are untested on a printer; the owner prints one cap and the three `stem` numbers are adjusted from that.
