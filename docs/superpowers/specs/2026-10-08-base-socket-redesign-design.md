# Open-top base the cap slides into: design

Status: Implemented. Requested by the owner in chat on 2026-10-08 (screenshots of the editor and of the same artwork in another vendor's generator). Supersedes the "Base" paragraph and the base-fit blocker of `2026-10-08-keycap-base-hollow-cap-design.md`; everything else there stands.

## Why

The first base was a closed block under the cap. The owner wants the real arrangement: a **base that is open on top and slightly bigger than the cap, with the cap sliding down inside it**, at least half of the cap's height inside the base, adjustable by the user. The owner's own logo (a ring around a monitor, opaque white background) was also rejected by the Trace base-fit rule ("too small to hold the switch base") although another generator handles it; the new geometry makes the fit rule far less strict.

## Settings and parameters

`settings.cap_insertion`: number from 0.5 to 1.0, default 0.5 (fraction of the cap height that is inside the base at rest). Validated by `SaveClickerRequest` (numeric, between 0.5 and 1, missing means 0.5), shown in the editor as "Cap depth inside base" (slider, 50 to 100 percent, shown for generator bodies).

`keycap.base` (mm; replaces the old numbers; every value is a guess to be tuned by a test print, see "What is not verified"):

```
clearance 0.3     gap between the cap and the pocket wall, each side
wall      2.0     base wall thickness around the pocket
plate     1.5     floor plate the switch snaps into
floor     1.5     solid floor: the base is CLOSED at the bottom (owner request, 2026-10-08)
under     7.0     total height below the plate: floor + the enclosed switch pocket (5.5)
cavity    16.2    square pocket under the plate for the switch body (enclosed: floor below, plate above, reached only through the cutout; switch pins must be trimmed or the switch pinless)
cutout    14.1    square hole through the plate (MX plate cutout plus 0.1)
rest_gap  8.0     distance from the plate top to the cap's bottom face at rest
spacing   10      gap between the parts in the print layout
hex       '#6B7280'
```

(`height` and `gap` from the previous design are removed; old rows that still carry them are ignored.)

## Base geometry

Outlines (all centred on the cap's frame, CCW, in mm):
- **pocket outline** = the cap outline grown by `clearance`;
- **base outline** = the cap outline grown by `clearance + wall`.
Square: rounded square, corner radius + the growth; Circle: radius + the growth; Trace: dilate the same coarse mask the cap outline was traced from by the growth distance, trace, simplify (0.03 mm), outer CCW.

Heights (z measured upward from the base's own bottom, which is z 0):
- floor, z 0 to `floor`: the whole base outline, solid (closed bottom);
- zone A, z `floor` to `under`: base outline with an enclosed square pocket `cavity` x `cavity` centred on the stem;
- plate, z `under` to `under + plate`: base outline with the `cutout` x `cutout` hole through it;
- pocket, z `under + plate` to the rim at `top = under + plate + rest_gap + cap_insertion * keycap.height`: pocket outline is empty (open top), the ring between the pocket and base outlines is solid.

One closed manifold shell built from planar faces and wall strips, welded, and passed through `verifiedMesh`: outer walls 0..top (base outline); rim face at `top` (base outline minus pocket outline, facing up); pocket walls from the plate top to `top` facing into the pocket; plate top face at `under + plate` (pocket outline minus cutout, facing up); cutout walls through the plate; plate underside at `under` (cavity minus cutout... the face between the cavity outline and the cutout facing down); pocket walls `floor`..`under` facing into the switch pocket; pocket floor face at `floor` (the cavity square, facing up); bottom face at 0 (the whole base outline, facing down, no hole). The base prints upright, open top up, with no supports (the only overhang is a 1.05 mm ledge around the cutout; the enclosed switch pocket has the plate as its ceiling).

**Fit rule** (blocker `This shape is too small to hold the switch base. Make the artwork larger or choose Square or Circle.`): the square `cutout + 2*0.8` centred on the stem lies inside the **cap outline** (corners, edge midpoints and no outline hole or island inside it); and the cavity square `cavity` centred on the stem lies inside the **base outline**. The old inset-based rule is removed. Verification failure of the shell is still a blocker (`The switch base could not be built for this shape. ...`).

## Layouts

`assembled` (editor): the cap as today (its bottom face at z 0); the base translated so that its plate top is at z `-rest_gap`, i.e. the base's z 0 maps to `-(rest_gap + under + plate)`; the rim then sits at z `cap_insertion * keycap.height`, so the lower part of the cap is inside the pocket. `print`: the cap as today; the base stays upright (no rotation) with its z 0 on the bed, translated in +x by `outline width + spacing` where the width is that of the base outline (so the clearance to the cap and colour parts is at least `spacing`).

## Hollow cap and solid cap

Unchanged. Note for the README: a solid cap has a flat bottom that touches the switch housing sooner than a hollow cap, so it gives less key travel; the hollow cap is the one that matches a real keycap. The README states this and that `rest_gap` decides where the cap sits.

## What is not verified

The MX vertical dimensions (plate to housing top, housing top to stem top, the stem engagement) are not confirmed against a real switch or printed part. `rest_gap`, `under`, `cavity`, `clearance` and `cutout` are provisional. The owner prints one set and reports; the README tells them which numbers to change.

## Tests

Node: pocket and base outlines for Square, Circle, Trace (growth distances, CCW, manifold when extruded); the base shell closed manifold with volume = (base outline area x top - pocket area x (top - under - plate) - cavity² x under - cutout² x plate) within 1 percent for Square, Circle and a Trace logo; vertical rays (through the cutout: only the cavity, then nothing above the plate; through the plate: material from under to under+plate; through the rim ring: 0..top; through the pocket above the plate: nothing); assembled layout (rim at cap_insertion x height, plate top at -rest_gap, the cap's lower part inside the pocket: the cap outline plus clearance fits within the pocket outline; no cap/base overlap), print layout upright and clear by spacing; the fit rule on an owner-like logo (opaque white background, ring ellipse around a monitor with a stand and a dot, 500 px) at scale 1 in Trace is NOT blocked, and a 6 mm blob IS blocked; `cap_insertion` 0.5 and 1.0 change the rim height as specified; `build.mjs` end to end. PHP: `cap_insertion` validation (0.4 and 1.1 fail, 0.5 and 1 pass, missing = 0.5). Browser: Trace of the owner-like logo builds; Square, Circle, Trace previews show the cap partly inside the base; the slider moves the rim.
