# Two-stage generation: restyled photo preview, then 3D model

Date: 2026-10-01
Status: Approved (implemented)
Builds on: `2026-10-01-foundation-core-creator-design.md` (implemented)

## 1. Context and goal

Today a customer uploads a photo and the app asks a 3D provider to build a model straight from it. Testing showed the 3D result is only as good as the picture it is built from: a plain portrait does not give a collectible figure. The look the product wants (chibi vinyl toy, fondant clay figure, sleepy designer figure) comes from restyling the photo first with an image model, then converting the restyled picture to 3D.

Goal: replace the single step with two stages.

1. **Restyle** the customer's photo into a figure-style image using OpenAI's image-edit API and the style's prompt.
2. **Preview and approve**: the customer sees the restyled image and approves or retries.
3. **Build 3D** from the approved image using Meshy, producing a textured GLB for the on-screen preview and an STL for printing.

The end product is a **physical figurine printed in a single colour and hand-painted** (sub-project 3, the shop). The 3D model therefore must be printable, and the STL is the print file.

## 2. Decisions

| Decision | Choice | Why |
|---|---|---|
| Restyle provider | OpenAI image edit, behind an `ImageStylizer` interface | Owner's testing: closest to the target look. Interface keeps Gemini or others possible. |
| 3D provider | Meshy, behind the existing `ModelProvider` interface | Owner has a Meshy account. Its API matches our adapter. |
| User flow | Preview, then confirm (two charges) | Bad restyles never reach the expensive 3D step. |
| Architecture | Separate `stylizations` record in front of the existing `creations` | The reviewed and hardened 3D flow stays almost untouched. |
| Deliverable | Physical figurine, single colour, hand-painted | Request untextured-print geometry (STL) plus textured GLB for preview. |
| Prompts | Stored per style in `styles.prompt`, sent unchanged | Same text the owner tests by hand; no prompt assembly in code. |

## 3. Scope

In scope: the stylization record and flow, `ImageStylizer` with OpenAI and mock implementations, `RestylePhoto` job, approve, retry and discard, `MeshyProvider`, STL storage, UI pages, sweeper and prune commands, daily limit and kill switch, tests, README.

Out of scope: the gallery, the shop and the admin STL download (sub-project 3), a Gemini restyler, automatic mesh printability analysis (we rely on prompt rules and the owner's manual check before printing), real payments, email notifications, multi-image style references (a later improvement), upload moderation beyond the providers' own refusals.

## 4. Flow

1. On `/create` the customer uploads a photo and picks a style. The server validates and re-encodes the photo (existing rules), charges the **restyle fee**, creates a `stylization` (`queued`) and dispatches `RestylePhoto`.
2. `RestylePhoto` calls `ImageStylizer::stylize()` with the photo and the style, stores the result image and marks the stylization `ready`.
3. The preview page `/stylizations/{id}` shows the original and the restyled image side by side with **Build 3D model (N credits)** and **Try again (restyle fee)**.
4. **Approve** creates a normal 3D creation from the restyled image (re-encoded as a clean JPEG), charges the style's 3D price, marks the stylization `approved`, links it to the creation and deletes the original photo and the restyle result files. The existing `GenerateCreation` job then runs against Meshy.
5. **Retry** discards the current preview and starts a new restyle from the same photo, charging the restyle fee again.
6. A failed 3D creation can be retried from the same restyled image (new creation, new 3D charge) without paying for another restyle.

## 5. Data model

**`stylizations`** (new): `id`, `user_id` (cascade), `style_id`, `source_image_path` (nullable), `result_image_path` (nullable), `status` (`queued|processing|ready|approved|failed|discarded`), `error` (text, nullable), `cost_credits`, `creation_id` (nullable, FK, null on delete), timestamps. Index on (`status`, `created_at`).

**`credit_ledger`** (changed): add `stylization_id` (nullable FK, null on delete) and a unique index on (`stylization_id`, `reason`). New `LedgerReason` values: `stylize` and `stylize_refund`. The existing unique index on (`creation_id`, `reason`) is unchanged; the new rows carry a null `creation_id`.

**`creations`** (changed): add `print_model_path` (nullable) for the STL.

**Enums:** `StylizationStatus` with the six values above. `ready` is a stable waiting state, not terminal; `approved`, `failed` and `discarded` are terminal.

## 6. Credits

- Restyle fee: `credits.restyle_cost` (default 1), charged when a stylization is created and again for each retry.
- 3D price: the style's existing `credit_cost`, charged at approval or when retrying a failed 3D creation.
- `CreditService` gains `spendForStylization(User, int, Stylization)` and `refundStylization(Stylization): ?CreditLedgerEntry`, with the same row-locking and idempotency as the existing creation methods.
- Refunds are per stage: a failed restyle refunds the restyle fee; a failed 3D build refunds the 3D price only.

## 7. Providers

### 7.1 `ImageStylizer`

```php
interface ImageStylizer
{
    /** Restyle a photo (absolute local path) using the style's prompt; returns PNG bytes. */
    public function stylize(string $photoPath, Style $style): string;
}
```

Throws the existing `TransientProviderException` or `PermanentProviderException`. Bound by `config('stylizer.provider')` (`mock` or `openai`).

**`OpenAiStylizer`:** `POST https://api.openai.com/v1/images/edits` (multipart) with `model`, the photo as `image`, `prompt` = `$style->prompt` unchanged, `size`, `quality`, `input_fidelity` (default `low`, see `OPENAI_INPUT_FIDELITY`), `output_format=png`. The response carries the image as base64 in `data[0].b64_json`; no download step. Error mapping:

| Response | Mapped to |
|---|---|
| 400 content-policy / moderation block | Permanent, customer-facing message "We can't process this photo." |
| Other 400 | Permanent |
| 401, 403, 429 with an insufficient-quota or billing code | Permanent for the customer, **critical log** for the operator |
| 429 rate limit, 5xx, connection error, timeout | Transient |

**`MockStylizer`:** returns the photo re-encoded with a visible coloured border so the preview page is testable without keys. `STYLIZER_MOCK_FAIL_RATE` (0 to 1) forces a permanent failure to exercise the refund path.

### 7.2 `MeshyProvider` (implements `ModelProvider`)

- **start():** `POST https://api.meshy.ai/openapi/v1/image-to-3d` with `Authorization: Bearer`, `image_url` = base64 data URI of the approved JPEG, `should_texture=true`, `enable_pbr=false`, `should_remesh=true`, `target_polycount` from the style's `provider_params.target_faces` (default 30000), `target_formats=["glb","stl"]`, optional `ai_model` from config.
- **status():** `GET .../image-to-3d/{id}`. `PENDING` maps to Pending, `IN_PROGRESS` to Running with `progress`, `SUCCEEDED` to Succeeded with `model_urls.glb`, `model_urls.stl` and `thumbnail_url`, `FAILED` or `CANCELED` to Failed with `task_error.message`.
- **download():** `GET` the URL (links expire, so the job always downloads). Only `https` URLs whose host ends in `meshy.ai` are fetched, to prevent the provider response being used to reach other hosts.
- **Errors:** 429, 5xx and connection errors are Transient. 400 and 404 are Permanent. 401, 402 and 403 are Permanent for the customer (refund) plus a critical log for the operator.
- **`ProviderResult`** gains an optional `printModelUrl`. `GenerateCreation` downloads both files and stores the STL as `creations/{id}/print.stl` in `print_model_path`.

`MockProvider` is extended to also return a mock STL so the whole path is testable.

## 8. Jobs, commands and queue timing

**`RestylePhoto(int $stylizationId)`** follows the hardened pattern of `GenerateCreation`:
- No-op if the stylization is already finished (`ready`, `approved`, `failed`, `discarded`).
- Deadline: `stylizer.job_deadline_seconds` (default 300) from `created_at`, then fail and refund.
- Conditional state changes (`queued` to `processing`, `processing` to `ready`), never an unconditional write. If the final update affects no row, the stored result file is deleted.
- Transient errors: release and retry every 10 seconds until the deadline. Permanent errors: fail and refund.
- Failure is one transaction: conditional update plus refund; a refund exception rolls the status back so the job retries. Shared in `StylizationService::markFailed(int $id, string $message): bool`.
- `WithoutOverlapping($id)` with `releaseAfter(5)` and `expireAfter(240)`; `$timeout = 150`; `failed()` calls `markFailed`.
- The HTTP client timeout is set below the job timeout.
- The database queue's `retry_after` default rises from 90 to 300 seconds. The invariant job timeout < lock expiry < `retry_after` holds for both jobs (60 < 80 < 300 and 150 < 240 < 300).

**Sweeper:** `creations:sweep` is extended to also fail and refund `queued` and `processing` stylizations older than `stylizer.job_deadline_seconds + 120`, using the same conditional path.

**Prune:** `stylizations:prune`, scheduled daily, discards `ready` and `failed` stylizations older than `stylizer.retention_days` (default 7): status `discarded`, files deleted, rows kept for the ledger.

## 9. Services

`StylizationService`:
- `create(User, UploadedFile, Style): Stylization`: sanitise and store the photo, enforce the daily limit, charge the restyle fee in a transaction, dispatch the job after commit.
- `approve(User, Stylization): Creation`: in one transaction, lock the stylization row first, then (inside `CreationService`) charge the 3D price. If already `approved`, return the existing creation. Only `ready` can be approved. Create the creation from a clean JPEG copy of the result, set `approved` and `creation_id`. After commit: dispatch `GenerateCreation`, then delete the original and result files. Lock order is stylization row, then user row, consistently. No unlocked read may precede `CreditService::spend`.
- `retry(User, Stylization): Stylization`: allowed from `ready` or `failed`. In one transaction, create the new stylization taking over the photo file, charge the restyle fee, mark the old one `discarded` and delete its result file.
- `discard(User, Stylization)`: allowed from `ready` or `failed`.

`CreationService` is refactored: the direct photo-upload entry point becomes an internal `createFromImage(User, Style, string $storedImagePath): Creation` used by approve and by **retry of a failed creation** (`POST /creations/{id}/retry`, allowed only when failed and owned, new 3D charge from the stored image). The public photo-upload route to `POST /creations` is removed so the preview step cannot be skipped.

## 10. Pages and routes

**Routes** (all behind `auth`):
- `POST /stylizations` (throttled): upload and start the restyle.
- `GET /stylizations/{id}`: preview page.
- `POST /stylizations/{id}/approve` and `POST /stylizations/{id}/retry` (throttled).
- `DELETE /stylizations/{id}`: discard.
- `GET /stylizations/{id}/files/{type}`, `type` in `original|result`, owner only.
- `POST /creations/{id}/retry`: re-run 3D from the stored image after a failure.
- Removed: the public `POST /creations`.

**`/create`:** button reads "Preview (1 credit)". It shows both costs: "Preview costs 1 credit. Building the 3D model afterwards costs N." Submitting requires only the preview fee; a soft warning links to Credits if the balance is below the 3D price. A short note says the photo is sent to OpenAI and Meshy to make the figure.

**`/stylizations/{id}`:**

| State | UI |
|---|---|
| queued, processing | The photo, a progress indicator, "Usually under a minute. You can leave this page." Polls every 3 seconds. |
| ready | Original and result side by side (stacked on phones). **Build 3D model (N credits)** is primary, **Try again (fee)** is secondary, plus "Start over with another photo". If the balance is below N, the primary button is disabled and reads "Add credits". |
| failed | Plain-language reason, a note that the preview credit was refunded, a link back to `/create`. |
| approved | Redirects to the creation page. |

**`/creations`:** a "Previews waiting for approval" strip above the grid (thumbnail plus Continue) for `ready` stylizations.

**`/creations/{id}`:** unchanged layout; while building it shows the approved restyled image. The failed state gains **Try again**. The GLB download is unchanged; the STL is not shown to customers.

**Credits page:** labels for `stylize` ("Preview") and `stylize_refund` ("Preview refund").

**Landing page:** "How it works" has four steps: upload, pick a style, preview and approve, get your 3D figure.

**Polling:** both polling pages stop on request errors (404, 419, 5xx, offline) and show a message, and stop after the deadline plus a margin with a "taking longer than expected" note. Statuses other than `failed` and `succeeded` never claim a refund. This also fixes two deferred findings of the first release.

**Quality bar for the new pages:** labelled controls, a live status region, visible keyboard focus, meaningful alt text on both images.

## 11. Errors and refunds

| Failure | Result |
|---|---|
| Provider refuses the photo (content policy) | Stylization `failed`, restyle fee refunded, message "We can't process this photo." |
| Rate limit, 5xx, timeout | Retried until the deadline, then failed and refunded. |
| Our provider account has a billing, quota or verification problem | Customer refunded, neutral "try again later" message, critical log entry. |
| Meshy fails the 3D build | Creation `failed`, 3D price refunded, the customer can retry 3D from the same image. |
| A job is stuck | Sweeper fails and refunds it (stylizations and creations). |
| Insufficient credits | At upload: validation error, nothing created. At approve: inline error on the preview page, the preview is kept. |

## 12. Security, privacy and cost control

- API keys exist only in `.env` (`services.openai.key`, `services.meshy.key`); never logged; no image bytes or full provider responses in logs.
- Customer photos are sent to OpenAI and Meshy; a notice on `/create` says so.
- Retention: the original photo is deleted at approval; unapproved previews are pruned after 7 days; all files live on the private disk and are served only through owner-checked routes.
- `stylizer.daily_limit_per_user` (default 30) bounds restyles per user per day. `STYLIZER_ENABLED=false` rejects new restyles with a clear message.
- In production the app refuses to start with `STYLIZER_PROVIDER=mock` or `MODEL_PROVIDER=mock`.
- The new endpoints are throttled per user like generation. Policies enforce ownership and state (approve and retry need `ready`; retry also from `failed`; discard needs `ready` or `failed`).
- Style prompts and style names contain no third-party brand names (guarded by tests). The operator is responsible for how closely finished figures resemble protected third-party designs before selling them; this is not legal advice.

## 13. Configuration

`.env`: `STYLIZER_PROVIDER` (`mock|openai`), `STYLIZER_ENABLED`, `OPENAI_API_KEY`, `OPENAI_IMAGE_MODEL`, `STYLIZER_MOCK_FAIL_RATE`, `MODEL_PROVIDER` (`mock|meshy`), `MESHY_API_KEY`, `MESHY_MODEL`, `CREDITS_RESTYLE_COST`, `STYLIZER_RETENTION_DAYS`, `STYLIZER_DAILY_LIMIT`.

`config/stylizer.php`: provider, enabled, model, quality (default medium), size (default 1024x1536), input fidelity (high), HTTP timeout, job deadline (300), retention days (7), daily limit (30).

## 14. Testing

- **Automated, with the mocks:** the full flow (upload, restyle job, preview, approve, 3D job), approving twice (one creation, one charge), retry (old discarded, fee charged), a refund at each stage with a failure injected, refund-throws-then-retry for the stylization failure transaction, the sweeper and prune commands, ownership and state policies, the daily limit, the kill switch, the production mock guard, removal of the direct upload route.
- **Request shape and error mapping:** `OpenAiStylizer` and `MeshyProvider` tested with faked HTTP responses for success and every row of the error mapping, including the host allow-list on downloads.
- **Rewritten tests:** the existing flow tests that post a photo straight to `/creations` are replaced by the new flow tests.
- **Browser end-to-end run with a real queue worker and the mocks** (as in the first release): upload, preview, approve, 3D, viewer, download, failure and refund at each stage, a second user's 403s, zero console errors.
- **Paid smoke command** (`providers:smoke {photo} {style}`): runs one real restyle and one real Meshy job with the owner's keys, for manual verification. Never part of the test suite.

## 15. Build order

1. Data model: `stylizations`, ledger changes, `print_model_path`, enums.
2. `ImageStylizer`, `MockStylizer`, `RestylePhoto`, `StylizationService::markFailed`, sweeper and prune.
3. `StylizationService` create, approve, retry and discard, `CreationService::createFromImage`, controllers, routes and policies.
4. Frontend: preview page, `/create` changes, previews strip, creation retry, credits labels, landing page, polling fixes.
5. `OpenAiStylizer`.
6. `MeshyProvider`, STL handling, mock STL.
7. End-to-end run, README, smoke command.

Everything that calls a real service (5 and 6) comes last and is fully covered with faked HTTP before any live call.

## 16. Assumptions to verify during the build

- **Meshy:** the create-task response shape and the exact status words (we saw `SUCCEEDED` and `FAILED`); the maximum base64 image size; that STL output and the chosen model version are available on the owner's plan; that the owner's subscription includes API access and how API credits are billed.
- **OpenAI:** whether the account needs Organization Verification for the chosen model; real per-image cost and latency for the chosen model, quality and size (measure before setting credit prices); that the `size` and `quality` values are accepted for that model.
- **Prompts:** only person Chibi, Clay and Sleepy have been tested by hand with OpenAI. The other eight style prompts need a manual test pass before launch.

## 17. Risks

- Likeness drift or refusals on photos of children or other sensitive content.
- Restyle quality varies between photos; prompt tuning continues after launch.
- Meshy may merge limbs or fill thin gaps; the owner's manual check before printing is the safety net until automatic checks exist.
- Two vendors mean two failure modes and two bills; credit prices must cover both.
