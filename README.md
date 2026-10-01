# 3D Maker

Turn a photo of a person, pet, or object into a downloadable 3D model. Laravel 12 + Inertia/Vue + MySQL.

## Setup (Laragon)

1. `composer install && npm install`
2. Copy `.env.example` to `.env`, then `php artisan key:generate`
3. Create the MySQL database `three_d_maker` and set `DB_*` in `.env`
4. `php artisan migrate --seed`
5. Run in three terminals: `php artisan serve`, `php artisan queue:listen --tries=1 --timeout=0`, `npm run dev`

(`composer run dev` does not work on Windows: `pail` needs the `pcntl` extension.)

## Model provider

`MODEL_PROVIDER=mock` (default) generates a placeholder cube after `MODEL_MOCK_DELAY_SECONDS`.
Set `MODEL_MOCK_FAIL_RATE` (0-1) to exercise the refund path; restart the queue listener after
changing `.env`. Real providers implement `App\Services\ModelProviders\ModelProvider`.

## Tests

`php artisan test` (uses in-memory sqlite; the dev database is MySQL `three_d_maker`).

## Docs

Design specs: `docs/superpowers/specs/`. Implementation plans: `docs/superpowers/plans/`.
