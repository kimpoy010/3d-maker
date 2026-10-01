# 3D Maker — Sub-project 1: Foundation + Core Creator

Date: 2026-10-01
Status: Approved (implemented)

## 1. Context

The goal is a web app modeled on [mukha.ph](https://mukha.ph) (photo-to-AI-portrait platform), but producing **3D figurine/bust models** instead of 2D portraits. The full product is decomposed into sub-projects:

| # | Sub-project | Contents |
|---|---|---|
| 1 | **Foundation + core creator** (this spec) | Scaffold, auth, landing, upload, style picker, queued generation, 3D viewer, download, credits ledger |
| 2 | Gallery | Style Explorer, public gallery, share links, privacy toggle |
| 3 | Shop | Product catalog, cart, stubbed checkout, orders, admin order queue |
| 4 | Partners | Partner accounts, shared credits, locations, events, devices, kiosk capture |
| 5 | Real integrations | Real 3D provider, real payments (PayMongo/Xendit), moderation, hardening |

Each sub-project gets its own spec, plan and build.

## 2. Decisions

- **Stack:** Laravel + Inertia/Vue + Tailwind + MySQL (Laragon).
- **Output:** textured GLB model of a person, pet or object.
- **Generation:** hosted image-to-3D API behind a `ModelProvider` interface; `MockProvider` is the default for now.
- **Styles:** subject type (person/pet/object) + look preset (realistic, cartoon, clay, chibi), stored in the database.
- **Credits:** append-only ledger, free credits at signup, stubbed top-up.
- **Status updates:** client polling (no websockets).
- **Payments/fulfillment:** stubbed (out of scope here).

## 3. Scope

In scope: auth, landing page, create flow, queued generation, creation status/viewer/download/delete, creations list, credits balance/ledger/stub top-up.

Out of scope: public gallery, sharing, real payments, orders/shop, admin, partners, content moderation, real provider integration.

## 4. Data model

- **users** — standard Laravel auth (Breeze, Inertia/Vue).
- **styles** — `id, subject (person|pet|object), name, look, provider_params (json), credit_cost, active, preview_image`.
- **creations** — `id, user_id, style_id, source_image_path, status (queued|processing|succeeded|failed), provider_job_id, model_path, thumbnail_path, error, cost_credits, timestamps`.
- **credit_ledger** — append-only: `id, user_id, delta, reason (signup|topup|generation|refund), creation_id nullable, created_at`. Balance = `SUM(delta)`. A unique constraint on (`creation_id`, `reason`) for `generation`/`refund` rows keeps refunds idempotent.

## 5. Generation flow

1. User uploads a photo and picks a style. Server validates type, size and minimum resolution.
2. In a single DB transaction: check balance, insert `-cost` ledger row, and create the `creations` row as `queued`. Insufficient balance returns a clear error and creates nothing. `GenerateCreation` is dispatched only after the transaction commits.
3. The job calls `ModelProvider::start()`, then polls `status()` until done or timed out.
4. On success: download the GLB and thumbnail into private storage, mark `succeeded`.
5. On failure: mark `failed`, insert a `+cost` refund ledger row (once per creation).
6. The creation page polls `GET /creations/{id}` every 3 s and renders the viewer on success.

A scheduled `creations:sweep` command (every five minutes) fails and refunds any creation still `queued`/`processing` longer than the generation timeout plus a two-minute grace, covering lost queue payloads and dispatch failures.

## 6. Provider adapter

```php
interface ModelProvider
{
    public function start(string $imagePath, Style $style): string;   // provider job id
    public function status(string $providerJobId): ProviderResult;
    public function download(string $url): string;                    // bytes behind a result URL
}

final class ProviderResult
{
    public function __construct(
        public readonly ProviderState $state,   // Pending | Running | Succeeded | Failed
        public readonly ?string $modelUrl = null,
        public readonly ?string $thumbnailUrl = null,
        public readonly ?string $error = null,
        public readonly ?int $progress = null,
    ) {}
}
```

- **MockProvider** (default in local/test): Pending → Running → Succeeded over a configurable delay, returning bundled sample GLBs per subject. `MOCK_FAIL_RATE` forces failures to exercise refunds.
- **Real provider** (Meshy/Tripo/fal — chosen in sub-project 5): not built now; the interface mirrors what those APIs share (submit image → task id → poll → GLB URL).
- **Selection:** `config/models.php` → `'provider' => env('MODEL_PROVIDER', 'mock')`; a service provider binds the interface. Nothing else knows the active provider.
- **Style mapping:** `styles.provider_params` holds provider-neutral hints (e.g. `{"look":"clay","texture":true,"target_faces":30000}`); each provider class translates them.

### `GenerateCreation` job

- Hard overall timeout of ~10 min → mark `failed` ("timed out") and refund.
- Re-polls with `release($delay)` and backoff, never sleeping in the worker.
- Transient errors (network, 5xx, rate limit) are retried every 10 s until the overall deadline; permanent errors (invalid image, content rejected) fail immediately and refund.
- Downloads the model from the provider (via `ModelProvider::download`) into our storage, because provider URLs expire. The mock generates its GLB/PNG bytes in-process, so no binary fixtures are stored.
- Idempotent: a creation already `succeeded` or `failed` is a no-op, so no double refunds.

## 7. Pages and UI

| Route | Page | Purpose |
|---|---|---|
| `/` | Landing | Hero, sample viewers, how it works, CTA |
| `/login`, `/register` | Auth | Breeze defaults, restyled; registration grants free signup credits |
| `/create` | Create | Upload → choose subject and look → review cost and submit |
| `/creations` | My Creations | Grid with thumbnail, status badge, date |
| `/creations/{id}` | Creation | Polling status, then viewer, download GLB, delete (owner only) |
| `/credits` | Credits | Balance, ledger history, stub "Add credits" |

**Create page:** drag-and-drop upload with preview and photo tips; subject tabs and look cards from `styles` with preview and cost; submit button shows cost and balance and is disabled (linking to `/credits`) when balance is too low.

**Creation page states:** `queued`/`processing` shows progress and the source photo (safe to leave); `succeeded` shows the viewer and download; `failed` shows a plain-language error, notes the refund, and links back to `/create`.

**Viewer:** `ModelViewer.vue` using Three.js `GLTFLoader` + `OrbitControls` (orbit, zoom, auto-rotate, lighting preset). Takes a model URL and handles loading/error states. Isolated for reuse by later sub-projects.

**Visual direction:** clean and warm like Mukha — white space, rounded cards, one strong accent color, light and dark mode. Finalized during implementation.

## 8. Files and security

- Uploads and models live on a private disk and are served through authorized controller routes (owner only), not public URLs.
- Uploaded images are re-encoded server-side to strip metadata and reject disguised files.
- Per-user rate limit on the generate endpoint.
- Policies ensure users can only view, download or delete their own creations.

## 9. Testing

- Unit: `MockProvider` state transitions; ledger balance calculation.
- Feature: create flow (validation, insufficient credits, success path), ownership policies, signup grants credits.
- Job tests against the mock covering success, failure and timeout, asserting ledger balance in each (spent on success, refunded on failure, never refunded twice).

## 10. Open items for later sub-projects

- Real provider choice and cost per generation (sub-project 5).
- Content moderation for uploaded photos (sub-project 5).
- Public sharing semantics (sub-project 2).
