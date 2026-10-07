# Keycap base, hollow cap option and background removal: design

Status: requested by the owner in chat on 2026-10-08 ("build the base now", "add an option for the user if they want a hollow cap or not"; the background fix was agreed in the same thread). Extends `2026-10-07-cherry-mx-keycap-body-design.md`.

## Why

The first keycap release produced only the cap. A usable clicker is two printed pieces: the **cap** with the artwork and the **base** that holds the Cherry MX switch. The owner also wants a hollow-cap choice, and images without transparency (JPG, white-background PNG) must trace by their subject, not as a rectangle.

## What the user sees

- Under "Base shape": two switches, **Hollow cap** (default off) and **Remove background** (default on).
- The preview shows the assembled clicker: cap above, base below with a gap, switch not drawn.
- Downloads: the 3MF has the colour parts, `Cap` and `Base` objects; the STL zip adds the base STL. The base is exported flipped (switch plate down) and placed beside the cap so each piece sits flat on the bed.
- New red messages: "This shape is too small to hold the switch base. Make the artwork larger or choose Square or Circle." and, for the hollow cap, a print note when no cavity fits.

## Settings

`settings.hollow_cap` boolean, default `false`; `settings.remove_background` boolean, default `true`. Missing values on old designs mean those defaults. Validated and normalised by `SaveClickerRequest`.

## Background removal

`removeBackground(image)` in `raster.js`, used by `analyzeArtwork` for generated keycap bodies unless `settings.remove_background` is false (legacy bodies only when it is true), and exported as `prepareArtwork(image, settings, body)` so the editor's palette suggestion sees the same pixels.
1. Do nothing if the image already has real transparency (more than 2 percent of pixels with alpha below 128).
2. Take the opaque border pixels (the outer ring of the image). Their dominant colour (median per channel) is the background candidate. Require at least 85 percent of border pixels within RGB distance 48 of it; otherwise do nothing.
3. Flood fill from every border pixel that is within distance 48 of the candidate (4-connected, matching the same tolerance against the candidate colour, not against the neighbour) and set alpha 0 on the reached pixels. Interior regions of the same colour that the flood does not reach stay (letters, holes).
4. Return a new RGBA array; never mutate the input.

## Parts and parameters

New body-config keys (defaults, mm):

```
keycap.hollow = {wall: 1.6, plate: 2.0, boss_radius: 3.6}
keycap.base   = {height: 12, plate: 1.5, wall: 2.0, cutout: 14.1, gap: 4.0, spacing: 10, hex: '#6B7280'}
```

**Hollow cap** (only when `hollow_cap` is true; the solid cap is unchanged): one closed manifold shell. Outline walls 0..H; top face at H; a cavity from z 0 up to `H - plate` bounded by the outline inset by `wall`, with a solid boss island of radius `boss_radius` around the stem; the cross socket cut into the boss from z 0 to `socket_depth`. Cavity roof faces down at `H - plate`. For Square and Circle the inset is analytic (rounded square: radius `corner_radius - wall` clamped to >= 0.5; circle: radius `size/2 - wall`); for Trace the cavity is the raster erosion of the outline by `wall`, minus the boss disc, traced on the coarse grid, simplified (0.03 mm), pieces below 4 mm² dropped. If no cavity piece remains, fall back to the solid cap and add a note. The hollow cap needs supports for the cavity roof when printed upright; the README and the editor hint say so.

**Base**: one closed manifold shell on the same outline: outline walls 0..`height`; top face at `height` with a square hole `cutout` x `cutout` centred on the stem (the standard MX plate cutout plus 0.1 mm); that hole's walls from `height - plate` to `height`; underside of the plate (facing down) at `height - plate`; a cavity from z 0 to `height - plate` bounded by the outline inset by `wall` (same inset routine as the hollow cap); open at the bottom. Blocker if the cutout square expanded by 0.8 mm does not lie inside the inset region: "This shape is too small to hold the switch base. Make the artwork larger or choose Square or Circle."

**Faces and welding.** All shells are built from planar faces (earcut, polygons with holes, shared ring vertices) and wall strips, then welded by exact coordinates so `isClosedManifold` holds. No overlapping shells.

**Layouts.** `buildParts(analysis, settings, body, {layout})`: `'assembled'` (default; used by the editor): the cap as today, the base translated to z in `[-(gap + height), -gap]`; `'print'` (used by `build.mjs`): the cap as today, the base rotated 180 degrees about the x axis (x, -y, `height - z`) and translated in +x by `outline width + spacing` so it clears the cap, plate down. Part kinds: cap body `body` named `Cap`; base `base` named `Base`. The 3MF and STL zip handle any part kind generically.

## Numbers that are guesses

Cavity wall, plate thicknesses, boss radius, base height 12, gap 4.0 and the 14.1 cutout come from public MX plate guidance and common practice. They are untested on a printer; the owner prints one set and reports; every value lives in the body config and a re-seed does not overwrite an existing body.

## Tests

Node: `removeBackground` on a white-background logo, a coloured border, a noisy border (no change), an already-transparent image (no change), interior white letters preserved; hollow and base shells closed manifold with exact volumes (outline area, cavity area, plate, boss) for Square, Circle and Trace on a synthetic logo; a vertical ray through the stem centre for both; blockers; layouts (assembled z ranges, print layout flipped and clear of the cap); `build.mjs` end to end producing Cap and Base objects. PHP: booleans validated and defaulted, builder passes config. Browser: toggles, preview assembled, a real build and 3MF/STL inspection.

## Not in this release

Switch clip notches beyond the plain 14.1 mm cutout, hot-swap sockets, per-part colours for the base, the Rectangle shape, Invert and Interior-holes controls, and hollow-cap print orientation changes.
