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
- The scheduler must run so stuck creations get failed and refunded: `php artisan schedule:work` in development, and this cron entry in production: `* * * * * php artisan schedule:run`. It runs `creations:sweep` every five minutes. The queue worker's retry window is governed by the job's `retryUntil()`, so no `--tries` flag is needed.

## Model provider

`MODEL_PROVIDER=mock` (default) generates a placeholder cube after `MODEL_MOCK_DELAY_SECONDS`.
Set `MODEL_MOCK_FAIL_RATE` (0-1) to exercise the refund path; restart the queue listener after
changing `.env`. Real providers implement `App\Services\ModelProviders\ModelProvider`.

## Tests

`php artisan test` (uses in-memory sqlite; the dev database is MySQL `three_d_maker`).

## Docs

Design specs: `docs/superpowers/specs/`. Implementation plans: `docs/superpowers/plans/`.
