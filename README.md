# 3D Maker

Turn a photo of a person, pet, or object into a downloadable 3D model. Laravel 12 + Inertia/Vue + MySQL.

## Setup (Laragon)

1. `composer install && npm install`
2. Copy `.env.example` to `.env`, then `php artisan key:generate`
3. Create the MySQL database `three_d_maker` and set `DB_*` in `.env`
4. `php artisan migrate --seed`
5. Run in three terminals: `php artisan serve`, `php artisan queue:listen --timeout=0`, `npm run dev`

(`composer run dev` does not work on Windows: `pail` needs the `pcntl` extension.)

## PHP and runtime requirements

- `memory_limit` of at least 256M (Laragon's default 512M is fine). Uploads are decoded with GD, which counts against it.
- `upload_max_filesize` of at least 10M and `post_max_size` of at least 12M (photos may be up to 10 MB, 40 megapixels).
- A cache store that supports atomic locks: `database` or `redis`, not `array` or a per-host `file` store. `WithoutOverlapping` and the mock provider both use the cache.
- The scheduler must run so stuck creations get failed and refunded: `php artisan schedule:work` in development, and this cron entry in production: `* * * * * php artisan schedule:run`. It runs `creations:sweep` every five minutes and `stylizations:prune` daily. The queue worker's retry window is governed by the job's `retryUntil()`, so no `--tries` flag is needed.

## Model provider

`MODEL_PROVIDER=mock` (default) generates a placeholder cube after `MODEL_MOCK_DELAY_SECONDS`.
Set `MODEL_MOCK_FAIL_RATE` (0-1) to exercise the refund path; restart the queue listener after
changing `.env`. Real providers implement `App\Services\ModelProviders\ModelProvider`.

## Two-stage generation

A customer's photo is first restyled by an image model, then the approved picture is turned into a 3D model:

1. Upload a photo and pick a style. A restyle preview is made (₱25 by default).
2. Review the preview next to the original. **Try again** makes a new preview (another fee, the old one is discarded).
3. **Build 3D model** approves the preview and charges the style's price (₱50 by default).
4. The 3D provider builds a GLB for the on-screen viewer and an STL print file. Viewing the model is free.
5. **Unlock downloads** (₱100 by default) pays once per creation to download both the GLB and the STL.

### Pricing

Customers see pesos. Underneath, the ledger stores credits where **1 credit = ₱1**, so ₱25 is 25 credits. Prices are config: `CREDITS_RESTYLE_COST`, `CREDITS_DOWNLOAD_COST`, the per-style `credit_cost` (seeded at 50) and the free `CREDITS_SIGNUP` balance (100). Failed steps are refunded automatically.

### Running the tests

The suite resets its database and must only ever run on in-memory SQLite (`phpunit.xml`). A cached config (`php artisan config:cache`) ignores `phpunit.xml`, so `tests/TestCase.php` refuses to boot in that case; run `php artisan config:clear` first. Do not `config:cache` on a development machine.

### Configuration

| Key | Default | Meaning |
|---|---|---|
| `STYLIZER_PROVIDER` | `mock` | Restyle provider: `mock` or `openai`. |
| `STYLIZER_ENABLED` | `true` | Kill switch. `false` rejects new previews with a clear message. |
| `OPENAI_API_KEY` | empty | OpenAI key, used when `STYLIZER_PROVIDER=openai`. |
| `OPENAI_IMAGE_MODEL` | `gpt-image-2.5-flare` | OpenAI image model. Fallback if it is ever renamed or removed: `gpt-image-2` (documented, same parameters). |
| `OPENAI_IMAGE_QUALITY` | `medium` | Image quality passed to OpenAI. |
| `OPENAI_INPUT_FIDELITY` | `low` | `low` lets the style prompt reshape the face (button eyes, big head); `high` keeps the photo's features closely but fights chibi/toy looks. |
| `OPENAI_OUTPUT_FORMAT` | `jpeg` | Image format requested from OpenAI. JPEG keeps the response ~150 KB; a 2-3 MB PNG body can crawl on slower links and hit the request timeout. `png` is lossless. |
| `OPENAI_OUTPUT_COMPRESSION` | `90` | JPEG/WebP quality (0-100). |
| `OPENAI_IMAGE_SIZE` | `1024x1536` | Image size passed to OpenAI. |
| `MODEL_PROVIDER` | `mock` | 3D provider: `mock` or `meshy`. |
| `MESHY_API_KEY` | empty | Meshy key, used when `MODEL_PROVIDER=meshy`. |
| `MESHY_MODEL` | unset | Meshy model version (`ai_model`). Use `latest` (Meshy 7.1) for the best detail; unset uses Meshy's default. |
| `MESHY_GEOMETRY_RESOLUTION` | unset | `standard`, `2k` or `4k`. Higher is smoother (finest surface detail) and costs 5 extra Meshy credits per build; needs `MESHY_MODEL=latest` or `meshy-7.1`. Unset sends nothing. |
| `CREDITS_RESTYLE_COST` | `1` | Credits charged per preview, including each retry. |
| `STYLIZER_RETENTION_DAYS` | `7` | Unapproved previews are discarded after this many days. |
| `STYLIZER_DAILY_LIMIT` | `30` | Most previews (retries included) one user may start per day. |
| `STYLIZER_MOCK_FAIL_RATE` | `0` | 0 to 1. Makes the mock restyler fail, to exercise the refund path. |
| `MODEL_MOCK_DELAY_SECONDS` | `6` | How long the mock 3D build takes. |
| `MODEL_MOCK_FAIL_RATE` | `0` | 0 to 1. Makes the mock 3D build fail, to exercise the refund path. |
| `DB_QUEUE_RETRY_AFTER` | `300` | Database queue retry window. Must stay above 240 (see below). |

The queue worker caches configuration, so restart `php artisan queue:listen` after changing `.env`.

### Trying the real providers

Set `OPENAI_API_KEY`, `MESHY_API_KEY`, `STYLIZER_PROVIDER=openai` and `MODEL_PROVIDER=meshy`, then run:

```
php artisan providers:smoke photo.jpg chibi
```

It asks for confirmation, runs ONE restyle and ONE 3D build, and saves the results under `storage/app/private/smoke/{timestamp}/` (`original.jpg`, `restyled.png`, `model.glb`, `print.stl`, `thumbnail.png`). Inspect the files by hand. **It makes paid API calls.** The style may be a numeric style id or a look such as `chibi`. Use `--subject=pet` or `--subject=object` when the style name exists for more than one subject, and `--skip-3d` to stop after the restyle. The automated tests only ever use the mock providers.

Production refuses `mock` providers: resolving the restyler or the 3D provider with `STYLIZER_PROVIDER=mock` or `MODEL_PROVIDER=mock` throws when `APP_ENV=production`.

### Prerequisites to confirm with the vendors

- OpenAI: the account may need Organization Verification before GPT Image models are available. Confirm the chosen model, quality and size are accepted on your account.
- Meshy: confirm your subscription includes API access, how API credits are billed, and that STL output and the chosen model version are available on your plan.

### Scheduler and queue

- Run the scheduler. It runs `creations:sweep` every five minutes (fails and refunds stuck creations and previews) and `stylizations:prune` daily (deletes expired unapproved previews). Use `php artisan schedule:work` in development and a cron entry `* * * * * php artisan schedule:run` in production.
- Keep `DB_QUEUE_RETRY_AFTER` above 240 seconds (default 300). The restyle job can run for minutes, and a lower value would let a second worker pick up a job that is still running.
- In production run `php artisan queue:work` under a process supervisor (systemd, Supervisor). The job-level `$timeout` governs how long a job may run; it is not enforced on Windows, which has no `pcntl`. The development script uses `queue:listen --timeout=0` so a slow real OpenAI call is not killed by the listener's default 60 seconds.

### Privacy and retention

Customer photos are sent to OpenAI (restyle) and the restyled picture is sent to Meshy (3D build) when the real providers are enabled. The original photo is deleted when the preview is approved. The approved preview is kept with the creation until the customer deletes it. Unapproved previews and their originals are deleted after `STYLIZER_RETENTION_DAYS` (default 7). The STL is stored for the operator at `storage/app/private/creations/{id}/print.stl` and is never shown to customers.

### Launch checklist

- [ ] Run `php artisan providers:smoke` with your real keys and inspect the files it saves.
- [ ] Try the eight style prompts that have not been tried by hand in ChatGPT: person Realistic and Cartoon, pet Realistic, Cartoon, Clay and Sleepy, and object Realistic and Clay. (Person Chibi, Clay and Sleepy were tried by hand.)
- [ ] Confirm Meshy returns an STL on your plan.
- [ ] Measure the real cost per restyle and per 3D build, then set the credit prices (`CREDITS_RESTYLE_COST` and the style prices) to cover both vendors.
- [ ] Confirm the scheduler runs and `DB_QUEUE_RETRY_AFTER` is above 240.
- [ ] Set `STYLIZER_PROVIDER=openai` and `MODEL_PROVIDER=meshy` in production (mock is refused).

## Clicker maker

A separate tool, free to design with and paid to download. Users upload flat artwork, the browser splits it into colours and raises each colour to its own height in a live 3D preview, and after paying (default ₱50, once per design) they download a 3MF with one named part per colour plus a zip of one STL per colour.

The geometry lives in `resources/clicker/` as plain JavaScript. The editor imports it for the preview, and the queued job `BuildClickerFiles` runs the same code through Node (`resources/clicker/build.mjs`) to write the paid files, so what you see is what prints. A failed build refunds the unlock automatically and the user can unlock again; the five-minute `creations:sweep` also fails and refunds a build stuck for too long.

**Node is required on every machine that runs the queue worker** (`node --version`, then `npm ci`). Set `CLICKER_NODE_BINARY` if it is not on the PATH.

| Key | Default | Meaning |
|---|---|---|
| `CREDITS_CLICKER_COST` | `50` | Price to unlock one design's downloads. |
| `CLICKER_MAX_PER_USER` | `20` | Saved designs per user. |
| `CLICKER_NODE_BINARY` | `node` | The Node executable used by the build job. |
| `CLICKER_BUILD_TIMEOUT` | `90` | Seconds before a build process is killed. |

**Cherry MX keycap body.** The seeded body "Cherry MX keycap" is generated in code (`generator: mx_keycap`), so it has no model files. The old placeholder slab is no longer seeded. Run `php artisan db:seed --class=ClickerBodySeeder` to create the keycap; the seeder never reactivates a body you have deactivated.

The user picks a base shape in the editor (saved as `settings.base_shape`, default `square`):

- **Square**: a rounded square keycap outline.
- **Circle**: a round outline.
- **Trace**: follows the silhouette of the uploaded artwork. It needs a plain or transparent background (see Remove background below); a busy background gives a blocky outline. The shape must be roughly 26 mm across (round) to hold the base; if it is smaller a red message appears ("This shape is too small to hold the switch base. Make the artwork larger or choose Square or Circle."), so make the artwork larger or use Square or Circle. The position (left/right, up/down) sliders are hidden for Trace because they would move the whole keycap; any saved offsets still apply.

All sizes are millimetres and live in the body config under `keycap` (defaults from `ClickerBody::sampleConfig()`): `size` 30, `rim` 2, `corner_radius` 4, `height` 8, `socket_depth` 4.5, and the cross-socket numbers `stem.span` 4.2, `stem.arm` 1.35, `stem.wall` 1.2. The stem numbers have not been tested on a real switch.

**Two printed pieces.** Every build produces a **Cap** (the colour parts plus the body, one closed solid with the cross socket open at the bottom) and a **Base** (the housing that holds the Cherry MX switch). The 3MF has the colour parts and the `Cap` and `Base` objects. The STL zip has one file per piece: `NN-colour-<hex>.stl` for each colour, `NN-cap.stl` and `NN-base.stl` (NN is the running number).

How each piece prints:

- **Cap**: upright, as modelled (cross socket facing the plate). The solid cap prints without supports. The hollow cap needs supports under its cavity roof.
- **Base**: exported already flipped, plate down, and placed beside the cap, so it sits flat on the bed. No supports.

Options that come with the keycap body (saved in the design's `settings`):

- **Hollow cap** (`hollow_cap`, default off). The cap becomes a shell with a cavity under the roof, with a boss around the stem. This saves material, but the cavity roof needs supports when printed upright. If the shape is too small for a cavity, the cap stays solid and the editor says so.
- **Remove background** (`remove_background`). A single plain background colour that touches the artwork's edge is made transparent before the build, so it does not become part of the print (useful for JPGs and white-background PNGs, and it is what lets Trace follow the subject). It needs a plain background: a busy or noisy edge is left unchanged, as is an image that is already transparent. Only background connected to the edge is removed, so interior regions of the same colour (letters, holes) stay. It is on by default for keycap bodies; designs saved before this setting read as off for old bodies (and hollow off). Turn the toggle off for artwork that really has a solid background.

**Numbers that are guesses.** These come from public MX plate guidance and common practice and have not been tested on a real printer or switch. All are millimetres under `keycap` in the body config, with these defaults:

- `keycap.stem`: `span` 4.2, `arm` 1.35, `wall` 1.2 (the cross socket).
- `keycap.hollow`: `wall` 1.6, `plate` 2.0, `boss_radius` 3.6.
- `keycap.base`: `height` 12, `plate` 1.5, `wall` 2.0, `cutout` 14.1, `gap` 4.0 (preview gap to the cap), `spacing` 10 (between printed parts), `hex` `#6B7280`.

Change them in the active body's row (its `config` JSON, merging the `keycap.stem`, `keycap.hollow` and `keycap.base` blocks). A re-seed does NOT overwrite an existing body; also change `sampleConfig()` for new installs, or add a new body. The app is reached at the `php artisan serve` address (<http://127.0.0.1:8000>) with `npm run dev` running alongside.

**Adding another clicker body.** Static bodies are model files you have printed and tested. Put the STL (or several) in `resources/clicker/bodies/<slug>/` and add an entry to `database/seeders/ClickerBodySeeder.php` with the cap size, the artwork area, the highest layer, the smallest printable detail and the switch it takes (see `ClickerBody::legacyConfig()` for every field and its unit).

**Launch checklist.**

- [ ] Node is installed on the queue-worker host, together with the build dependencies (`earcut`, `fflate` and `pngjs` are runtime `dependencies`, so `npm ci --omit=dev` is enough).
- [ ] Print one cap and one base and fit a real Cherry MX switch; adjust `keycap.stem`, `keycap.base` and `keycap.hollow` in the body config (the existing row is not overwritten by re-seeding; edit it or add a new body).
- [ ] Slice the cap and base in the slicer's default (Regular) mode and check the cross socket in the layer preview.
- [ ] The old development body `placeholder-slab`, if it exists in the database, is deactivated (`active = false`). The seeder no longer creates it.
- [ ] Click both download links (3MF and STL zip) of an unlocked design in a real browser and open the files in a slicer.

**Tests.** `npm run test:clicker` runs the geometry suite (Node); `vendor/bin/pest` runs the rest. The Pest suite refuses to start unless it is on in-memory SQLite: run `php artisan config:clear` first and never run `php artisan config:cache` on a development machine.

## Tests

`php artisan test` (uses in-memory sqlite; the dev database is MySQL `three_d_maker`).

## Docs

Design specs: `docs/superpowers/specs/`. Implementation plans: `docs/superpowers/plans/`.
