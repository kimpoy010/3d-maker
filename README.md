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

1. Upload a photo and pick a style. A restyle preview is made (a small credit fee).
2. Review the preview next to the original. **Try again** makes a new preview (another fee, the old one is discarded).
3. **Build 3D model** approves the preview and charges the style's price.
4. The 3D provider builds a GLB for the on-screen viewer and an STL print file.

### Configuration

| Key | Default | Meaning |
|---|---|---|
| `STYLIZER_PROVIDER` | `mock` | Restyle provider: `mock` or `openai`. |
| `STYLIZER_ENABLED` | `true` | Kill switch. `false` rejects new previews with a clear message. |
| `OPENAI_API_KEY` | empty | OpenAI key, used when `STYLIZER_PROVIDER=openai`. |
| `OPENAI_IMAGE_MODEL` | `gpt-image-1.5` | OpenAI image model. |
| `OPENAI_IMAGE_QUALITY` | `medium` | Image quality passed to OpenAI. |
| `OPENAI_IMAGE_SIZE` | `1024x1536` | Image size passed to OpenAI. |
| `MODEL_PROVIDER` | `mock` | 3D provider: `mock` or `meshy`. |
| `MESHY_API_KEY` | empty | Meshy key, used when `MODEL_PROVIDER=meshy`. |
| `MESHY_MODEL` | unset | Optional Meshy model version (`ai_model`). Unset uses Meshy's default. |
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

## Tests

`php artisan test` (uses in-memory sqlite; the dev database is MySQL `three_d_maker`).

## Docs

Design specs: `docs/superpowers/specs/`. Implementation plans: `docs/superpowers/plans/`.
