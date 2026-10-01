# Two-Stage Generation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace single-step "photo to 3D" with two stages: restyle the photo with OpenAI (preview and approve), then build the 3D model with Meshy, producing a textured GLB for preview and an STL for printing.

**Architecture:** A new `stylizations` record sits in front of the existing `creations`. An `ImageStylizer` interface (OpenAI and mock implementations) is driven by a `RestylePhoto` job built on the same hardened pattern as `GenerateCreation` (conditional state changes, refund in the same transaction, no-overlap lock, deadline). Approving a preview creates a normal 3D creation from the restyled image; `MeshyProvider` plugs into the existing `ModelProvider` interface. Everything real (OpenAI, Meshy) is built last behind the mocks.

**Tech Stack:** PHP 8.4, Laravel 12, MySQL (dev) / SQLite in-memory (tests), Inertia + Vue 3 + TypeScript, Pest, GD, Laravel HTTP client.

**Spec:** `docs/superpowers/specs/2026-10-01-two-stage-generation-design.md`

## Global Constraints

- Work on branch `feat/two-stage-generation` (already contains the spec and everything from `feat/core-creator` and `feat/style-prompts`). Commit messages end with the line `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Never push; the owner pushes.
- Laravel 12, Pest tests in `tests/Feature`, SQLite in-memory for tests, MySQL `three_d_maker` for the dev DB (never `migrate:fresh`; the dev DB is shared state, clean up any rows/files you create).
- Money-path rules inherited from the first release (do not weaken): state changes are **conditional** (`WHERE status IN (...)`), a refund shares the transaction with the status change (a refund exception rolls the status back), refunds are idempotent through the unique ledger index, and **inside a DB transaction no plain SELECT may run before the credit `spend` call** (a locking `lockForUpdate()` read is fine; load models before the transaction).
- Queue timing invariant: job `$timeout` < overlap-lock `expireAfter` < queue `retry_after`. After this plan `DB_QUEUE_RETRY_AFTER` defaults to 300; `GenerateCreation` stays 60 < 80, `RestylePhoto` is 150 < 240.
- Defaults from the spec: restyle fee 1 credit (`credits.restyle_cost`), preview retention 7 days, daily restyle limit 30 per user, restyle job deadline 300 seconds, sweeper threshold deadline + 120 seconds.
- The kit's `vue-tsc --noEmit` has 2 known pre-existing TS2688 errors (`./resources/js/types`, `vue/tsx`); "type-check clean" means no others. Frontend uses plain URL strings (no Ziggy/Wayfinder in our pages). Frontend tasks must be verified **at runtime in a browser** (the first release showed that build and type checks miss runtime errors).
- Style prompts and names contain no third-party brand names (a test guards this); do not add any.
- The customer never sees or downloads the STL in this plan (admin access comes with the shop sub-project).
- API keys only in `.env`, read through `config('services.*')`; never log keys, image bytes, or full provider responses.

## File Structure

```
app/
  Console/Commands/{SweepStaleCreations (modify),PruneStylizations,SmokeProviders}.php
  Enums/{StylizationStatus (new),LedgerReason (modify)}.php
  Exceptions/{StylizationNotReadyException,DailyLimitReachedException,StylizerDisabledException}.php
  Http/Controllers/{StylizationController,StylizationFileController (new),CreationController (modify)}.php
  Http/Requests/StoreStylizationRequest.php            (renamed from StoreCreationRequest)
  Http/Resources/StylizationResource.php
  Jobs/{RestylePhoto (new),GenerateCreation (modify)}.php
  Models/{Stylization (new),Creation,CreditLedgerEntry (modify)}.php
  Policies/{StylizationPolicy (new),CreationPolicy (modify)}.php
  Providers/CreatorServiceProvider.php                  (modify)
  Services/
    StylizationService.php                              (new)
    CreationService.php                                 (modify)
    Credits/CreditService.php                           (modify)
    Stylizers/{ImageStylizer,MockStylizer,OpenAiStylizer}.php
    ModelProviders/{MeshyProvider (new),ProviderResult,MockProvider,MockAssets (modify)}.php
config/{stylizer.php (new),credits.php,models.php,services.php,queue.php (modify)}
database/{migrations (3 new),factories/StylizationFactory.php}
resources/js/
  composables/usePolling.ts
  pages/{Create,Credits,Welcome}.vue (modify), pages/stylizations/Show.vue (new), pages/creations/{Index,Show}.vue (modify)
routes/{web.php,console.php} (modify)
tests/Feature/*  (new files per task; CreationFlowTest rewritten)
```

---

### Task 1: Data model

**Files:**
- Create: `database/migrations/2026_10_02_000001_create_stylizations_table.php`, `..._000002_add_stylization_id_to_credit_ledger_table.php`, `..._000003_add_print_model_path_to_creations_table.php`
- Create: `app/Enums/StylizationStatus.php`, `app/Models/Stylization.php`, `database/factories/StylizationFactory.php`
- Modify: `app/Enums/LedgerReason.php`, `app/Models/CreditLedgerEntry.php`, `app/Models/Creation.php`
- Test: `tests/Feature/StylizationModelTest.php`

**Interfaces:**
- Produces: `StylizationStatus` (`Queued|Processing|Ready|Approved|Failed|Discarded`, values lowercase; `isWorking(): bool` true for queued/processing; `isTerminal(): bool` true for approved/failed/discarded). `LedgerReason::Stylize = 'stylize'`, `LedgerReason::StylizeRefund = 'stylize_refund'`. `Stylization` model (fillable `user_id, style_id, source_image_path, result_image_path, status, error, cost_credits, creation_id`; casts `status`; relations `user()`, `style()`, `creation()`), `Stylization::factory()` (status Queued, cost 1, source path `stylizations/test/original.jpg`). `CreditLedgerEntry` fillable gains `stylization_id`. `Creation` fillable gains `print_model_path`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/StylizationModelTest.php`:

```php
<?php

use App\Enums\LedgerReason;
use App\Enums\StylizationStatus;
use App\Models\Creation;
use App\Models\CreditLedgerEntry;
use App\Models\Style;
use App\Models\Stylization;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

it('creates a stylization that belongs to a user and a style', function () {
    $stylization = Stylization::factory()->create();

    expect($stylization->status)->toBe(StylizationStatus::Queued)
        ->and($stylization->user)->toBeInstanceOf(User::class)
        ->and($stylization->style)->toBeInstanceOf(Style::class)
        ->and($stylization->creation)->toBeNull()
        ->and($stylization->cost_credits)->toBe(1);
});

it('classifies stylization statuses', function () {
    expect(StylizationStatus::Queued->isWorking())->toBeTrue()
        ->and(StylizationStatus::Processing->isWorking())->toBeTrue()
        ->and(StylizationStatus::Ready->isWorking())->toBeFalse()
        ->and(StylizationStatus::Ready->isTerminal())->toBeFalse()
        ->and(StylizationStatus::Approved->isTerminal())->toBeTrue()
        ->and(StylizationStatus::Failed->isTerminal())->toBeTrue()
        ->and(StylizationStatus::Discarded->isTerminal())->toBeTrue();
});

it('allows one charge row and one refund row per stylization', function () {
    $stylization = Stylization::factory()->create();
    $row = fn (LedgerReason $reason) => CreditLedgerEntry::create([
        'user_id' => $stylization->user_id,
        'delta' => -1,
        'reason' => $reason,
        'stylization_id' => $stylization->id,
    ]);

    $row(LedgerReason::Stylize);
    $row(LedgerReason::StylizeRefund);

    expect(fn () => $row(LedgerReason::Stylize))->toThrow(UniqueConstraintViolationException::class);
});

it('stores a print model path on creations', function () {
    $creation = Creation::factory()->create(['print_model_path' => 'creations/1/print.stl']);

    expect($creation->fresh()->print_model_path)->toBe('creations/1/print.stl');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/StylizationModelTest.php`
Expected: FAIL (classes and columns missing).

- [ ] **Step 3: Migrations**

`database/migrations/2026_10_02_000001_create_stylizations_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stylizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('style_id')->constrained();
            $table->string('source_image_path')->nullable();
            $table->string('result_image_path')->nullable();
            $table->string('status')->default('queued');
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('cost_credits');
            $table->foreignId('creation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stylizations');
    }
};
```

`database/migrations/2026_10_02_000002_add_stylization_id_to_credit_ledger_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_ledger', function (Blueprint $table) {
            $table->foreignId('stylization_id')->nullable()->constrained()->nullOnDelete();

            // One charge row and one refund row per stylization; NULLs never collide.
            $table->unique(['stylization_id', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::table('credit_ledger', function (Blueprint $table) {
            $table->dropUnique(['stylization_id', 'reason']);
            $table->dropConstrainedForeignId('stylization_id');
        });
    }
};
```

`database/migrations/2026_10_02_000003_add_print_model_path_to_creations_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creations', function (Blueprint $table) {
            // STL used for printing; never shown to customers in this release.
            $table->string('print_model_path')->nullable()->after('model_path');
        });
    }

    public function down(): void
    {
        Schema::table('creations', function (Blueprint $table) {
            $table->dropColumn('print_model_path');
        });
    }
};
```

If SQLite refuses the foreign-key column in `Schema::table` (check the test run), keep the unique index and make the column a plain `unsignedBigInteger('stylization_id')->nullable()` in the SQLite case only, but the MySQL migration must keep the constrained foreign key. Report which you did.

- [ ] **Step 4: Enum, model, factory, existing-model edits**

`app/Enums/StylizationStatus.php`:

```php
<?php

namespace App\Enums;

enum StylizationStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Ready = 'ready';
    case Approved = 'approved';
    case Failed = 'failed';
    case Discarded = 'discarded';

    /** Still being produced by the restyle job. */
    public function isWorking(): bool
    {
        return in_array($this, [self::Queued, self::Processing], true);
    }

    /** Nothing more will ever happen to it. `Ready` waits for the customer and is not terminal. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Approved, self::Failed, self::Discarded], true);
    }
}
```

`app/Models/Stylization.php`:

```php
<?php

namespace App\Models;

use App\Enums\StylizationStatus;
use Database\Factories\StylizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stylization extends Model
{
    /** @use HasFactory<StylizationFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'style_id', 'source_image_path', 'result_image_path',
        'status', 'error', 'cost_credits', 'creation_id',
    ];

    protected function casts(): array
    {
        return ['status' => StylizationStatus::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function style(): BelongsTo
    {
        return $this->belongsTo(Style::class);
    }

    public function creation(): BelongsTo
    {
        return $this->belongsTo(Creation::class);
    }
}
```

`database/factories/StylizationFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\StylizationStatus;
use App\Models\Stylization;
use App\Models\Style;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Stylization> */
class StylizationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'style_id' => Style::factory(),
            'source_image_path' => 'stylizations/test/original.jpg',
            'status' => StylizationStatus::Queued,
            'cost_credits' => 1,
        ];
    }
}
```

Edit `app/Enums/LedgerReason.php`: add `case Stylize = 'stylize';` and `case StylizeRefund = 'stylize_refund';`.
Edit `app/Models/CreditLedgerEntry.php`: `protected $fillable = ['user_id', 'delta', 'reason', 'creation_id', 'stylization_id'];`.
Edit `app/Models/Creation.php`: add `'print_model_path'` to `$fillable` (after `'model_path'`).

- [ ] **Step 5: Run tests, migrate dev DB, commit**

```bash
php artisan test tests/Feature/StylizationModelTest.php
php artisan test
php artisan migrate
git add app database tests
git commit -m "feat: add stylizations table, ledger link and print model path

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```
Expected: new tests PASS, full suite PASS (106 plus new), migrate applies three migrations on MySQL.

---

### Task 2: Credits for stylizations

**Files:**
- Modify: `app/Services/Credits/CreditService.php`, `config/credits.php`
- Test: `tests/Feature/StylizationCreditsTest.php`

**Interfaces:**
- Consumes: `Stylization`, `LedgerReason::Stylize|StylizeRefund` (Task 1).
- Produces: `CreditService::spendForStylization(User $user, int $amount, Stylization $stylization): CreditLedgerEntry` (locks the user row, throws `InsufficientCreditsException`); `CreditService::refundStylization(Stylization $stylization): ?CreditLedgerEntry` (idempotent, null if nothing charged or already refunded); config `credits.restyle_cost` (default 1, env `CREDITS_RESTYLE_COST`). The existing `spend`/`refund` signatures and behaviour are unchanged.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/StylizationCreditsTest.php`:

```php
<?php

use App\Enums\LedgerReason;
use App\Exceptions\InsufficientCreditsException;
use App\Models\CreditLedgerEntry;
use App\Models\Stylization;
use App\Models\User;
use App\Services\Credits\CreditService;

beforeEach(function () {
    $this->credits = app(CreditService::class);
    $this->user = User::factory()->create();
    $this->credits->grant($this->user, 10, LedgerReason::Signup);
    $this->stylization = Stylization::factory()->create(['user_id' => $this->user->id]);
});

it('exposes the restyle fee in config', function () {
    expect(config('credits.restyle_cost'))->toBe(1);
});

it('charges a stylization and links the ledger row to it', function () {
    $entry = $this->credits->spendForStylization($this->user, 1, $this->stylization);

    expect($entry->delta)->toBe(-1)
        ->and($entry->reason)->toBe(LedgerReason::Stylize)
        ->and($entry->stylization_id)->toBe($this->stylization->id)
        ->and($entry->creation_id)->toBeNull()
        ->and($this->credits->balance($this->user))->toBe(9);
});

it('rejects a stylization charge above the balance and writes nothing', function () {
    expect(fn () => $this->credits->spendForStylization($this->user, 50, $this->stylization))
        ->toThrow(InsufficientCreditsException::class);

    expect($this->credits->balance($this->user))->toBe(10)
        ->and(CreditLedgerEntry::where('reason', LedgerReason::Stylize)->count())->toBe(0);
});

it('refunds a stylization exactly once', function () {
    $this->credits->spendForStylization($this->user, 1, $this->stylization);

    $first = $this->credits->refundStylization($this->stylization);
    $second = $this->credits->refundStylization($this->stylization);

    expect($first->delta)->toBe(1)
        ->and($first->reason)->toBe(LedgerReason::StylizeRefund)
        ->and($second)->toBeNull()
        ->and($this->credits->balance($this->user))->toBe(10);
});

it('does not refund a stylization that was never charged', function () {
    expect($this->credits->refundStylization($this->stylization))->toBeNull()
        ->and($this->credits->balance($this->user))->toBe(10);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/StylizationCreditsTest.php`
Expected: FAIL (methods and config missing).

- [ ] **Step 3: Config**

In `config/credits.php` add, after the `topup` entry:

```php
    // Credits charged for each restyle preview (and each retry of one).
    'restyle_cost' => (int) env('CREDITS_RESTYLE_COST', 1),
```

- [ ] **Step 4: Generalize CreditService (replace the whole file)**

`app/Services/Credits/CreditService.php`:

```php
<?php

namespace App\Services\Credits;

use App\Enums\LedgerReason;
use App\Exceptions\InsufficientCreditsException;
use App\Models\Creation;
use App\Models\CreditLedgerEntry;
use App\Models\Stylization;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class CreditService
{
    public function balance(User $user): int
    {
        return (int) CreditLedgerEntry::where('user_id', $user->id)->sum('delta');
    }

    public function grant(User $user, int $amount, LedgerReason $reason): CreditLedgerEntry
    {
        return CreditLedgerEntry::create([
            'user_id' => $user->id,
            'delta' => $amount,
            'reason' => $reason,
        ]);
    }

    /**
     * Charge a creation. Locks the user row so two concurrent submissions
     * cannot both pass the balance check.
     */
    public function spend(User $user, int $amount, Creation $creation): CreditLedgerEntry
    {
        return $this->charge($user, $amount, LedgerReason::Generation, ['creation_id' => $creation->id]);
    }

    /** Charge a restyle preview. Same locking and balance check as {@see spend()}. */
    public function spendForStylization(User $user, int $amount, Stylization $stylization): CreditLedgerEntry
    {
        return $this->charge($user, $amount, LedgerReason::Stylize, ['stylization_id' => $stylization->id]);
    }

    /** Give back what a creation cost. Returns null if there is nothing to refund. */
    public function refund(Creation $creation): ?CreditLedgerEntry
    {
        return $this->giveBack(LedgerReason::Generation, LedgerReason::Refund, 'creation_id', $creation->id);
    }

    /** Give back what a restyle preview cost. Returns null if there is nothing to refund. */
    public function refundStylization(Stylization $stylization): ?CreditLedgerEntry
    {
        return $this->giveBack(LedgerReason::Stylize, LedgerReason::StylizeRefund, 'stylization_id', $stylization->id);
    }

    /** @param array<string, int> $reference the ledger column linking the row to what was bought */
    private function charge(User $user, int $amount, LedgerReason $reason, array $reference): CreditLedgerEntry
    {
        return DB::transaction(function () use ($user, $amount, $reason, $reference) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $balance = $this->balance($user);
            if ($balance < $amount) {
                throw new InsufficientCreditsException($amount, $balance);
            }

            return CreditLedgerEntry::create([
                'user_id' => $user->id,
                'delta' => -$amount,
                'reason' => $reason,
            ] + $reference);
        });
    }

    private function giveBack(LedgerReason $charged, LedgerReason $refunded, string $column, int $id): ?CreditLedgerEntry
    {
        $spent = CreditLedgerEntry::where($column, $id)->where('reason', $charged)->first();

        if (! $spent) {
            return null;
        }

        try {
            return DB::transaction(function () use ($spent, $refunded, $column, $id) {
                $alreadyRefunded = CreditLedgerEntry::where($column, $id)->where('reason', $refunded)->exists();

                if ($alreadyRefunded) {
                    return null;
                }

                return CreditLedgerEntry::create([
                    'user_id' => $spent->user_id,
                    'delta' => -$spent->delta,
                    'reason' => $refunded,
                    $column => $id,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent refund won the race; the unique index kept it to one.
            return null;
        }
    }
}
```

- [ ] **Step 5: Run tests and commit**

```bash
php artisan test tests/Feature/StylizationCreditsTest.php tests/Feature/CreditServiceTest.php
php artisan test
git add app config tests
git commit -m "feat: charge and refund restyle previews through CreditService

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```
Expected: PASS, and the existing `CreditServiceTest` is untouched and still green (proves the refactor kept behaviour).

---

### Task 3: Stylizer interface, mock, config and bindings

**Files:**
- Create: `app/Services/Stylizers/ImageStylizer.php`, `app/Services/Stylizers/MockStylizer.php`, `config/stylizer.php`
- Modify: `app/Providers/CreatorServiceProvider.php`, `config/services.php`, `config/queue.php`, `.env.example`, `tests/Pest.php`
- Test: `tests/Feature/MockStylizerTest.php`

**Interfaces:**
- Produces:
  - `interface ImageStylizer { public function stylize(string $photoPath, Style $style): string; }` returns PNG bytes; throws `App\Services\ModelProviders\TransientProviderException` / `PermanentProviderException` (reused).
  - `MockStylizer`: re-encodes the photo as PNG with a 12 px coloured border; throws `PermanentProviderException` when `stylizer.mock.fail_rate` triggers or the file is unreadable.
  - Container binding `ImageStylizer::class` from `config('stylizer.provider')` (`mock` now; `openai` added in Task 11); unknown value throws `InvalidArgumentException`; **in production, resolving the mock stylizer or the mock model provider throws `RuntimeException`**.
  - Config keys: `stylizer.enabled`, `stylizer.provider`, `stylizer.job_deadline_seconds` (300), `stylizer.retention_days` (7), `stylizer.daily_limit` (30), `stylizer.openai.{model,quality,size,input_fidelity,timeout_seconds}`, `stylizer.mock.fail_rate`; `services.openai.key`, `services.meshy.{key,model}`.
  - Pest helper `fakeJpegBytes(int $width = 800, int $height = 800): string` in `tests/Pest.php`.

- [ ] **Step 1: Write the failing tests**

First add the helper to the end of `tests/Pest.php`:

```php
/** A real JPEG for tests that need file contents on the fake disk. */
function fakeJpegBytes(int $width = 800, int $height = 800): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 120, 160, 200));

    ob_start();
    imagejpeg($image, null, 90);

    return (string) ob_get_clean();
}
```

`tests/Feature/MockStylizerTest.php`:

```php
<?php

use App\Models\Style;
use App\Services\ModelProviders\MockProvider;
use App\Services\ModelProviders\ModelProvider;
use App\Services\ModelProviders\PermanentProviderException;
use App\Services\Stylizers\ImageStylizer;
use App\Services\Stylizers\MockStylizer;

beforeEach(function () {
    config(['stylizer.mock.fail_rate' => 0]);
    $this->photo = tempnam(sys_get_temp_dir(), 'photo');
    file_put_contents($this->photo, fakeJpegBytes(300, 200));
    $this->style = Style::factory()->make();
});

it('returns a png of the same size with a coloured border', function () {
    $png = (new MockStylizer)->stylize($this->photo, $this->style);

    expect(substr($png, 0, 8))->toBe("\x89PNG\r\n\x1a\n");

    $image = imagecreatefromstring($png);
    expect([imagesx($image), imagesy($image)])->toBe([300, 200]);

    $corner = imagecolorsforindex($image, imagecolorat($image, 0, 0));
    expect([$corner['red'], $corner['green'], $corner['blue']])->toBe([230, 120, 90]);
});

it('refuses every photo when the fail rate is 1', function () {
    config(['stylizer.mock.fail_rate' => 1]);

    expect(fn () => (new MockStylizer)->stylize($this->photo, $this->style))
        ->toThrow(PermanentProviderException::class);
});

it('rejects unreadable photos', function () {
    expect(fn () => (new MockStylizer)->stylize('/no/such/photo.jpg', $this->style))
        ->toThrow(PermanentProviderException::class);
});

it('binds the mock stylizer by default', function () {
    expect(app(ImageStylizer::class))->toBeInstanceOf(MockStylizer::class);
});

it('rejects an unknown stylizer provider', function () {
    config(['stylizer.provider' => 'nope']);

    expect(fn () => app(ImageStylizer::class))->toThrow(InvalidArgumentException::class);
});

it('refuses mock providers in production', function () {
    app()->instance('env', 'production');

    expect(fn () => app(ImageStylizer::class))->toThrow(RuntimeException::class);
    expect(fn () => app(ModelProvider::class))->toThrow(RuntimeException::class);
});

it('still binds the mock model provider outside production', function () {
    expect(app(ModelProvider::class))->toBeInstanceOf(MockProvider::class);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/MockStylizerTest.php`
Expected: FAIL (classes and config missing).

- [ ] **Step 3: Config files**

`config/stylizer.php`:

```php
<?php

return [
    // Kill switch: false rejects new previews with a clear message.
    'enabled' => (bool) env('STYLIZER_ENABLED', true),

    // Which ImageStylizer to use: "mock" or "openai".
    'provider' => env('STYLIZER_PROVIDER', 'mock'),

    // A preview still unfinished after this long is failed and refunded.
    'job_deadline_seconds' => 300,

    // Unapproved previews are discarded after this many days.
    'retention_days' => (int) env('STYLIZER_RETENTION_DAYS', 7),

    // Maximum previews (including retries) one user may start per day.
    'daily_limit' => (int) env('STYLIZER_DAILY_LIMIT', 30),

    'openai' => [
        'model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-1.5'),
        'quality' => env('OPENAI_IMAGE_QUALITY', 'medium'),
        'size' => env('OPENAI_IMAGE_SIZE', '1024x1536'),
        'input_fidelity' => 'high',
        // Must stay below the RestylePhoto job timeout (150 s).
        'timeout_seconds' => 120,
    ],

    'mock' => [
        'fail_rate' => (float) env('STYLIZER_MOCK_FAIL_RATE', 0),
    ],
];
```

Read `config/services.php` and add these entries inside the returned array (keep the kit's existing ones):

```php
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
    ],

    'meshy' => [
        'key' => env('MESHY_API_KEY'),
        'model' => env('MESHY_MODEL'),
    ],
```

In `config/queue.php`, change the database connection's `retry_after` default from `90` to `300`: `'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 300),`. Also set `DB_QUEUE_RETRY_AFTER=300` in `.env.example`. Add a comment in the config: the restyle job's HTTP call can run for minutes, and the invariant job timeout < lock expiry < retry_after must hold.

Append to `.env.example`:

```
STYLIZER_PROVIDER=mock
STYLIZER_ENABLED=true
STYLIZER_MOCK_FAIL_RATE=0
STYLIZER_RETENTION_DAYS=7
STYLIZER_DAILY_LIMIT=30
CREDITS_RESTYLE_COST=1
OPENAI_API_KEY=
OPENAI_IMAGE_MODEL=gpt-image-1.5
MESHY_API_KEY=
```

Also add the same lines to your local `.env` (without the keys).

- [ ] **Step 4: Interface and mock**

`app/Services/Stylizers/ImageStylizer.php`:

```php
<?php

namespace App\Services\Stylizers;

use App\Models\Style;
use App\Services\ModelProviders\PermanentProviderException;
use App\Services\ModelProviders\TransientProviderException;

interface ImageStylizer
{
    /**
     * Restyle a photo (absolute local path) using the style's prompt. Returns PNG bytes.
     *
     * Exception messages are shown to the customer, so they must be plain and safe.
     *
     * @throws TransientProviderException when retrying later may succeed
     * @throws PermanentProviderException when the photo or request can never succeed
     */
    public function stylize(string $photoPath, Style $style): string;
}
```

`app/Services/Stylizers/MockStylizer.php`:

```php
<?php

namespace App\Services\Stylizers;

use App\Models\Style;
use App\Services\ModelProviders\PermanentProviderException;
use GdImage;

final class MockStylizer implements ImageStylizer
{
    private const BORDER_PX = 12;

    public function stylize(string $photoPath, Style $style): string
    {
        $rate = (float) config('stylizer.mock.fail_rate');

        if ($rate > 0 && random_int(1, 1_000_000) <= $rate * 1_000_000) {
            throw new PermanentProviderException("We can't process this photo. Please try a different one.");
        }

        $contents = is_file($photoPath) ? file_get_contents($photoPath) : false;
        $image = $contents === false ? false : @imagecreatefromstring($contents);

        if (! $image instanceof GdImage) {
            throw new PermanentProviderException('We could not read that photo.');
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $border = imagecolorallocate($image, 230, 120, 90);

        for ($i = 0; $i < self::BORDER_PX; $i++) {
            imagerectangle($image, $i, $i, $width - 1 - $i, $height - 1 - $i, $border);
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
```

- [ ] **Step 5: Bindings and the production guard**

Replace `register()` in `app/Providers/CreatorServiceProvider.php` and add imports (`App\Services\Stylizers\ImageStylizer`, `App\Services\Stylizers\MockStylizer`, `RuntimeException`):

```php
    public function register(): void
    {
        $this->app->bind(ModelProvider::class, function () {
            $name = config('models.provider');

            $this->refuseMockInProduction($name, 'MODEL_PROVIDER');

            return match ($name) {
                'mock' => new MockProvider,
                default => throw new InvalidArgumentException("Unknown model provider [{$name}]."),
            };
        });

        $this->app->bind(ImageStylizer::class, function () {
            $name = config('stylizer.provider');

            $this->refuseMockInProduction($name, 'STYLIZER_PROVIDER');

            return match ($name) {
                'mock' => new MockStylizer,
                default => throw new InvalidArgumentException("Unknown stylizer provider [{$name}]."),
            };
        });
    }

    /** Checked when the provider is resolved, not at boot, so artisan deploy commands still run. */
    private function refuseMockInProduction(string $name, string $envKey): void
    {
        if ($name === 'mock' && $this->app->isProduction()) {
            throw new RuntimeException("{$envKey}=mock is not allowed in production.");
        }
    }
```

- [ ] **Step 6: Run tests and commit**

```bash
php artisan test tests/Feature/MockStylizerTest.php
php artisan test
./vendor/bin/pint app config tests/Feature/MockStylizerTest.php tests/Pest.php
git add app config tests .env.example
git commit -m "feat: add ImageStylizer interface, mock, config and production guard

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```
Expected: PASS. (`.env.example` only; do not commit `.env`.)

---

### Task 4: StylizationService core and the RestylePhoto job

**Files:**
- Create: `app/Services/StylizationService.php`, `app/Jobs/RestylePhoto.php`
- Create: `app/Exceptions/StylizationNotReadyException.php`, `app/Exceptions/DailyLimitReachedException.php`, `app/Exceptions/StylizerDisabledException.php`
- Test: `tests/Feature/StylizationServiceTest.php`, `tests/Feature/RestylePhotoTest.php`

**Interfaces:**
- Consumes: `CreditService::spendForStylization` / `refundStylization` (Task 2), `ImageStylizer` (Task 3), `ImageSanitizer`, models (Task 1).
- Produces:
  - `StylizationService::create(User $user, UploadedFile $photo, Style $style): Stylization` (throws `StylizerDisabledException`, `DailyLimitReachedException`, `InsufficientCreditsException`, `InvalidImageException`; stores the cleaned photo at `stylizations/{uuid}/original.jpg`; dispatches `RestylePhoto` after commit)
  - `StylizationService::markProcessing(int $id): bool`, `markReady(int $id, string $resultPath): bool`, `markFailed(int $id, string $message): bool`, `discard(Stylization $stylization): bool` (all conditional)
  - `new RestylePhoto(int $stylizationId)`
  - exceptions as named (all extend `RuntimeException`; `StylizationNotReadyException` and the later approve/retry flow in Task 6 use it)

- [ ] **Step 1: Exceptions**

`app/Exceptions/StylizationNotReadyException.php`:

```php
<?php

namespace App\Exceptions;

use RuntimeException;

/** The stylization is not in a state that allows the requested action. */
class StylizationNotReadyException extends RuntimeException {}
```

`app/Exceptions/DailyLimitReachedException.php`:

```php
<?php

namespace App\Exceptions;

use RuntimeException;

class DailyLimitReachedException extends RuntimeException {}
```

`app/Exceptions/StylizerDisabledException.php`:

```php
<?php

namespace App\Exceptions;

use RuntimeException;

class StylizerDisabledException extends RuntimeException {}
```

- [ ] **Step 2: Write the failing service tests**

`tests/Feature/StylizationServiceTest.php`:

```php
<?php

use App\Enums\LedgerReason;
use App\Enums\StylizationStatus;
use App\Exceptions\DailyLimitReachedException;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\StylizerDisabledException;
use App\Jobs\RestylePhoto;
use App\Models\CreditLedgerEntry;
use App\Models\Style;
use App\Models\Stylization;
use App\Models\User;
use App\Services\Credits\CreditService;
use App\Services\StylizationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config(['credits.restyle_cost' => 1, 'stylizer.enabled' => true, 'stylizer.daily_limit' => 30]);

    $this->credits = app(CreditService::class);
    $this->service = app(StylizationService::class);
    $this->user = User::factory()->create();
    $this->credits->grant($this->user, 10, LedgerReason::Signup);
    $this->style = Style::factory()->create();
    $this->photo = fn () => UploadedFile::fake()->image('me.jpg', 800, 800);
});

it('charges the restyle fee, stores a clean photo and queues the job', function () {
    Queue::fake();

    $stylization = $this->service->create($this->user, ($this->photo)(), $this->style);

    expect($stylization->status)->toBe(StylizationStatus::Queued)
        ->and($stylization->user_id)->toBe($this->user->id)
        ->and($stylization->cost_credits)->toBe(1)
        ->and($this->credits->balance($this->user))->toBe(9);
    Storage::disk('local')->assertExists($stylization->source_image_path);
    expect($stylization->source_image_path)->toStartWith('stylizations/')->toEndWith('/original.jpg');
    Queue::assertPushed(RestylePhoto::class, fn ($job) => $job->stylizationId === $stylization->id);
});

it('writes nothing when the user cannot afford the preview', function () {
    Queue::fake();
    config(['credits.restyle_cost' => 50]);

    expect(fn () => $this->service->create($this->user, ($this->photo)(), $this->style))
        ->toThrow(InsufficientCreditsException::class);

    expect(Stylization::count())->toBe(0)
        ->and($this->credits->balance($this->user))->toBe(10)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    Queue::assertNothingPushed();
});

it('refuses new previews while the kill switch is off', function () {
    Queue::fake();
    config(['stylizer.enabled' => false]);

    expect(fn () => $this->service->create($this->user, ($this->photo)(), $this->style))
        ->toThrow(StylizerDisabledException::class);

    expect(Stylization::count())->toBe(0);
});

it('enforces the daily limit per user', function () {
    Queue::fake();
    config(['stylizer.daily_limit' => 2]);

    $this->service->create($this->user, ($this->photo)(), $this->style);
    $this->service->create($this->user, ($this->photo)(), $this->style);

    expect(fn () => $this->service->create($this->user, ($this->photo)(), $this->style))
        ->toThrow(DailyLimitReachedException::class);

    $other = User::factory()->create();
    $this->credits->grant($other, 5, LedgerReason::Signup);
    expect($this->service->create($other, ($this->photo)(), $this->style))->toBeInstanceOf(Stylization::class);
});

describe('state changes', function () {
    beforeEach(function () {
        $this->stylization = Stylization::factory()->create([
            'user_id' => $this->user->id,
            'style_id' => $this->style->id,
        ]);
        $this->credits->spendForStylization($this->user, 1, $this->stylization);
    });

    it('moves queued to processing to ready only through conditional updates', function () {
        expect($this->service->markProcessing($this->stylization->id))->toBeTrue()
            ->and($this->stylization->fresh()->status)->toBe(StylizationStatus::Processing)
            ->and($this->service->markReady($this->stylization->id, 'stylizations/x/result.png'))->toBeTrue();

        $fresh = $this->stylization->fresh();
        expect($fresh->status)->toBe(StylizationStatus::Ready)
            ->and($fresh->result_image_path)->toBe('stylizations/x/result.png')
            ->and($this->service->markReady($this->stylization->id, 'other.png'))->toBeFalse()
            ->and($this->service->markProcessing($this->stylization->id))->toBeFalse();
    });

    it('fails and refunds exactly once', function () {
        expect($this->service->markFailed($this->stylization->id, 'nope'))->toBeTrue()
            ->and($this->service->markFailed($this->stylization->id, 'again'))->toBeFalse();

        $fresh = $this->stylization->fresh();
        expect($fresh->status)->toBe(StylizationStatus::Failed)
            ->and($fresh->error)->toBe('nope')
            ->and($this->credits->balance($this->user))->toBe(10)
            ->and(CreditLedgerEntry::where('reason', LedgerReason::StylizeRefund)->count())->toBe(1);
    });

    it('never fails or refunds a preview that is already ready', function () {
        $this->service->markReady($this->stylization->id, 'stylizations/x/result.png');

        expect($this->service->markFailed($this->stylization->id, 'late'))->toBeFalse()
            ->and($this->stylization->fresh()->status)->toBe(StylizationStatus::Ready)
            ->and($this->credits->balance($this->user))->toBe(9);
    });

    it('rolls the status back when the refund throws, so a retry can finish', function () {
        $this->app->bind(CreditService::class, fn () => new class extends CreditService
        {
            public int $calls = 0;

            public function refundStylization(Stylization $stylization): ?CreditLedgerEntry
            {
                if ($this->calls++ === 0) {
                    throw new RuntimeException('db down');
                }

                return parent::refundStylization($stylization);
            }
        });
        $service = app(StylizationService::class);

        expect(fn () => $service->markFailed($this->stylization->id, 'x'))->toThrow(RuntimeException::class);
        expect($this->stylization->fresh()->status)->toBe(StylizationStatus::Queued);

        expect($service->markFailed($this->stylization->id, 'x'))->toBeTrue()
            ->and($this->credits->balance($this->user))->toBe(10);
    });

    it('discards a ready preview and deletes its files', function () {
        Storage::disk('local')->put('stylizations/x/original.jpg', 'o');
        Storage::disk('local')->put('stylizations/x/result.png', 'r');
        $this->stylization->update([
            'status' => StylizationStatus::Ready,
            'source_image_path' => 'stylizations/x/original.jpg',
            'result_image_path' => 'stylizations/x/result.png',
        ]);

        expect($this->service->discard($this->stylization->fresh()))->toBeTrue();

        $fresh = $this->stylization->fresh();
        expect($fresh->status)->toBe(StylizationStatus::Discarded)
            ->and($fresh->source_image_path)->toBeNull()
            ->and($fresh->result_image_path)->toBeNull();
        Storage::disk('local')->assertMissing('stylizations/x/original.jpg');
        Storage::disk('local')->assertMissing('stylizations/x/result.png');
    });

    it('will not discard a preview that is still being made', function () {
        expect($this->service->discard($this->stylization->fresh()))->toBeFalse()
            ->and($this->stylization->fresh()->status)->toBe(StylizationStatus::Queued);
    });
});
```

- [ ] **Step 3: Write the failing job tests**

`tests/Feature/RestylePhotoTest.php`:

```php
<?php

use App\Enums\LedgerReason;
use App\Enums\StylizationStatus;
use App\Jobs\RestylePhoto;
use App\Models\CreditLedgerEntry;
use App\Models\Style;
use App\Models\Stylization;
use App\Models\User;
use App\Services\Credits\CreditService;
use App\Services\ModelProviders\PermanentProviderException;
use App\Services\ModelProviders\TransientProviderException;
use App\Services\Stylizers\ImageStylizer;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config(['stylizer.mock.fail_rate' => 0, 'stylizer.job_deadline_seconds' => 300]);

    $this->credits = app(CreditService::class);
    $this->user = User::factory()->create();
    $this->credits->grant($this->user, 10, LedgerReason::Signup);

    $this->makeStylization = function (array $attrs = []) {
        $stylization = Stylization::factory()->create([
            'user_id' => $this->user->id,
            'style_id' => Style::factory(),
            'source_image_path' => 'stylizations/t/original.jpg',
        ] + $attrs);
        Storage::disk('local')->put('stylizations/t/original.jpg', fakeJpegBytes());
        $this->credits->spendForStylization($this->user, 1, $stylization);

        return $stylization;
    };

    $this->runJob = function (Stylization $stylization) {
        $job = (new RestylePhoto($stylization->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        return $job;
    };
});

it('stores the restyled image and keeps the charge', function () {
    $stylization = ($this->makeStylization)();

    ($this->runJob)($stylization);

    $fresh = $stylization->fresh();
    expect($fresh->status)->toBe(StylizationStatus::Ready)
        ->and($fresh->result_image_path)->toBe("stylizations/{$stylization->id}/result.png")
        ->and($this->credits->balance($this->user))->toBe(9);
    Storage::disk('local')->assertExists($fresh->result_image_path);
});

it('fails and refunds when the provider refuses the photo', function () {
    config(['stylizer.mock.fail_rate' => 1]);
    $stylization = ($this->makeStylization)();

    ($this->runJob)($stylization);
    ($this->runJob)($stylization); // a finished stylization is a no-op

    $fresh = $stylization->fresh();
    expect($fresh->status)->toBe(StylizationStatus::Failed)
        ->and($fresh->error)->toContain("can't process")
        ->and($this->credits->balance($this->user))->toBe(10)
        ->and(CreditLedgerEntry::where('reason', LedgerReason::StylizeRefund)->count())->toBe(1);
});

it('retries later on transient errors without failing', function () {
    $this->app->bind(ImageStylizer::class, fn () => new class implements ImageStylizer
    {
        public function stylize(string $photoPath, Style $style): string
        {
            throw new TransientProviderException('503');
        }
    });
    $stylization = ($this->makeStylization)();

    $job = ($this->runJob)($stylization);

    $job->assertReleased(delay: 10);
    expect($stylization->fresh()->status)->toBe(StylizationStatus::Processing)
        ->and($this->credits->balance($this->user))->toBe(9);
});

it('fails with the provider message on permanent errors', function () {
    $this->app->bind(ImageStylizer::class, fn () => new class implements ImageStylizer
    {
        public function stylize(string $photoPath, Style $style): string
        {
            throw new PermanentProviderException('Previews are temporarily unavailable. Please try again later.');
        }
    });
    $stylization = ($this->makeStylization)();

    ($this->runJob)($stylization);

    expect($stylization->fresh()->status)->toBe(StylizationStatus::Failed)
        ->and($stylization->fresh()->error)->toBe('Previews are temporarily unavailable. Please try again later.')
        ->and($this->credits->balance($this->user))->toBe(10);
});

it('times out, fails and refunds', function () {
    $stylization = ($this->makeStylization)();
    $stylization->forceFill(['created_at' => now()->subSeconds(301)])->save();

    ($this->runJob)($stylization);

    expect($stylization->fresh()->status)->toBe(StylizationStatus::Failed)
        ->and($stylization->fresh()->error)->toContain('timed out')
        ->and($this->credits->balance($this->user))->toBe(10);
});

it('does nothing for a preview that is already ready', function () {
    $stylization = ($this->makeStylization)(['status' => StylizationStatus::Ready, 'result_image_path' => 'stylizations/t/result.png']);

    ($this->runJob)($stylization);

    expect($stylization->fresh()->status)->toBe(StylizationStatus::Ready)
        ->and($this->credits->balance($this->user))->toBe(9);
});

it('deletes a result it stored if another run already finished the preview', function () {
    $this->app->bind(ImageStylizer::class, fn () => new class implements ImageStylizer
    {
        public function stylize(string $photoPath, Style $style): string
        {
            // Simulate the sweeper failing the preview while the provider call was running.
            Stylization::query()->update(['status' => StylizationStatus::Failed->value]);

            return 'png-bytes';
        }
    });
    $stylization = ($this->makeStylization)();

    ($this->runJob)($stylization);

    expect($stylization->fresh()->status)->toBe(StylizationStatus::Failed);
    Storage::disk('local')->assertMissing("stylizations/{$stylization->id}/result.png");
});

it('fails and refunds if the job itself blows up', function () {
    $stylization = ($this->makeStylization)();

    (new RestylePhoto($stylization->id))->failed(new RuntimeException('boom'));

    expect($stylization->fresh()->status)->toBe(StylizationStatus::Failed)
        ->and($this->credits->balance($this->user))->toBe(10);
});

it('allows one run per stylization at a time and keeps the timing invariant', function () {
    $job = new RestylePhoto(7);
    $lock = $job->middleware()[0];

    expect($lock)->toBeInstanceOf(WithoutOverlapping::class)
        ->and($lock->key)->toBe(7)
        ->and($lock->expiresAfter)->toBe(240)
        ->and($job->timeout)->toBeLessThan($lock->expiresAfter)
        ->and($lock->expiresAfter)->toBeLessThan((int) config('queue.connections.database.retry_after'));
});
```

If `$lock->key` or `$lock->expiresAfter` are not public in the installed Laravel, read the matching assertion in `tests/Feature/GenerateCreationTest.php` (the test named like "prevents overlapping runs") and use the same technique.

- [ ] **Step 4: Run to verify they fail**

Run: `php artisan test tests/Feature/StylizationServiceTest.php tests/Feature/RestylePhotoTest.php`
Expected: FAIL (classes missing).

- [ ] **Step 5: StylizationService (core)**

`app/Services/StylizationService.php`:

```php
<?php

namespace App\Services;

use App\Enums\StylizationStatus;
use App\Exceptions\DailyLimitReachedException;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\InvalidImageException;
use App\Exceptions\StylizerDisabledException;
use App\Jobs\RestylePhoto;
use App\Models\Style;
use App\Models\Stylization;
use App\Models\User;
use App\Services\Credits\CreditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class StylizationService
{
    public function __construct(
        private ImageSanitizer $sanitizer,
        private CreditService $credits,
    ) {}

    /**
     * Store a cleaned copy of the photo, charge the restyle fee and queue the restyle.
     *
     * @throws StylizerDisabledException
     * @throws DailyLimitReachedException
     * @throws InvalidImageException
     * @throws InsufficientCreditsException
     */
    public function create(User $user, UploadedFile $photo, Style $style): Stylization
    {
        $this->assertCanStart($user);

        $jpeg = $this->sanitizer->sanitize($photo->getRealPath());
        $path = 'stylizations/'.Str::uuid().'/original.jpg';
        Storage::disk('local')->put($path, $jpeg);

        try {
            $stylization = $this->chargeAndCreate($user, $style, $path);
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);

            throw $e;
        }

        RestylePhoto::dispatch($stylization->id);

        return $stylization;
    }

    /**
     * The kill switch and the daily limit. Runs BEFORE any transaction: a plain SELECT inside
     * the charging transaction would fix its snapshot before the user-row lock is taken.
     *
     * @throws StylizerDisabledException
     * @throws DailyLimitReachedException
     */
    public function assertCanStart(User $user): void
    {
        if (! config('stylizer.enabled')) {
            throw new StylizerDisabledException('Previews are temporarily unavailable.');
        }

        $today = Stylization::where('user_id', $user->id)->where('created_at', '>=', now()->startOfDay())->count();

        if ($today >= (int) config('stylizer.daily_limit')) {
            throw new DailyLimitReachedException('Daily preview limit reached.');
        }
    }

    /** Only an INSERT runs before the charge, which takes the user-row lock. */
    private function chargeAndCreate(User $user, Style $style, string $sourcePath): Stylization
    {
        return DB::transaction(function () use ($user, $style, $sourcePath) {
            $stylization = Stylization::create([
                'user_id' => $user->id,
                'style_id' => $style->id,
                'source_image_path' => $sourcePath,
                'status' => StylizationStatus::Queued,
                'cost_credits' => (int) config('credits.restyle_cost'),
            ]);

            $this->credits->spendForStylization($user, $stylization->cost_credits, $stylization);

            return $stylization;
        });
    }

    /** queued/processing to processing. Returns whether a row changed. */
    public function markProcessing(int $id): bool
    {
        return Stylization::whereKey($id)
            ->whereIn('status', $this->working())
            ->update(['status' => StylizationStatus::Processing->value, 'updated_at' => now()]) === 1;
    }

    /** queued/processing to ready, recording the result image. Returns whether a row changed. */
    public function markReady(int $id, string $resultPath): bool
    {
        return Stylization::whereKey($id)
            ->whereIn('status', $this->working())
            ->update([
                'status' => StylizationStatus::Ready->value,
                'result_image_path' => $resultPath,
                'error' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * Fail and refund atomically. Only a preview still being made can fail, and the refund
     * shares the transaction: if it throws, the status rolls back. Returns whether this call
     * moved the stylization to failed.
     */
    public function markFailed(int $id, string $message): bool
    {
        return DB::transaction(function () use ($id, $message) {
            $updated = Stylization::whereKey($id)
                ->whereIn('status', $this->working())
                ->update(['status' => StylizationStatus::Failed->value, 'error' => $message, 'updated_at' => now()]);

            if ($updated === 0) {
                return false;
            }

            $this->credits->refundStylization(Stylization::findOrFail($id));

            return true;
        });
    }

    /**
     * Throw a preview away (customer discard, retry, or retention prune): ready or failed only.
     * Deletes its files. Returns whether it was discarded.
     */
    public function discard(Stylization $stylization): bool
    {
        $discarded = Stylization::whereKey($stylization->id)
            ->whereIn('status', [StylizationStatus::Ready->value, StylizationStatus::Failed->value])
            ->update([
                'status' => StylizationStatus::Discarded->value,
                'source_image_path' => null,
                'result_image_path' => null,
                'updated_at' => now(),
            ]) === 1;

        if ($discarded) {
            Storage::disk('local')->delete(array_filter([$stylization->source_image_path, $stylization->result_image_path]));
        }

        return $discarded;
    }

    /** @return list<string> */
    private function working(): array
    {
        return [StylizationStatus::Queued->value, StylizationStatus::Processing->value];
    }
}
```

- [ ] **Step 6: RestylePhoto job**

`app/Jobs/RestylePhoto.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Stylization;
use App\Services\ModelProviders\PermanentProviderException;
use App\Services\ModelProviders\TransientProviderException;
use App\Services\StylizationService;
use App\Services\Stylizers\ImageStylizer;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RestylePhoto implements ShouldQueue
{
    use Queueable;

    /** Seconds to wait before retrying after a transient provider error. */
    private const TRANSIENT_RETRY_SECONDS = 10;

    /** Give up after this many unexpected (non-provider) exceptions. */
    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [5, 15, 30];

    /**
     * Hard per-attempt limit for the worker. The provider HTTP call has its own, shorter
     * timeout. Must stay below the lock expiry (240s), which must stay below the queue
     * connection's retry_after (300s).
     */
    public int $timeout = 150;

    public function __construct(public int $stylizationId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->stylizationId))->releaseAfter(5)->expireAfter(240)];
    }

    public function retryUntil(): CarbonInterface
    {
        return now()->addSeconds((int) config('stylizer.job_deadline_seconds') + 120);
    }

    public function handle(ImageStylizer $stylizer, StylizationService $stylizations): void
    {
        $stylization = Stylization::with('style')->find($this->stylizationId);

        if (! $stylization || ! $stylization->status->isWorking()) {
            return;
        }

        if ($stylization->created_at->addSeconds((int) config('stylizer.job_deadline_seconds'))->isPast()) {
            $stylizations->markFailed($stylization->id, 'The preview timed out.');

            return;
        }

        $stylizations->markProcessing($stylization->id);
        $disk = Storage::disk('local');

        try {
            $png = $stylizer->stylize($disk->path($stylization->source_image_path), $stylization->style);
        } catch (TransientProviderException) {
            $this->release(self::TRANSIENT_RETRY_SECONDS);

            return;
        } catch (PermanentProviderException $e) {
            $stylizations->markFailed($stylization->id, $e->getMessage());

            return;
        }

        $resultPath = "stylizations/{$stylization->id}/result.png";
        $disk->put($resultPath, $png);

        // Lost the race (swept, or already finished): drop what we stored.
        if (! $stylizations->markReady($stylization->id, $resultPath)) {
            $disk->delete($resultPath);
        }
    }

    /** Called by the queue when the job exhausts its retries or throws unexpectedly. */
    public function failed(?Throwable $exception): void
    {
        app(StylizationService::class)->markFailed($this->stylizationId, 'The preview failed unexpectedly.');
    }
}
```

- [ ] **Step 7: Run tests and commit**

```bash
php artisan test tests/Feature/StylizationServiceTest.php tests/Feature/RestylePhotoTest.php
php artisan test
./vendor/bin/pint app tests/Feature/StylizationServiceTest.php tests/Feature/RestylePhotoTest.php
git add app tests
git commit -m "feat: add StylizationService and the RestylePhoto job

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```
Expected: PASS. If the "deletes a result it stored" test fails because the status-flipping closure also needs `updated_at`, keep the test's intent (a concurrent finish) and adjust only the simulation.

---

### Task 5: Sweeper and prune commands

**Files:**
- Modify: `app/Console/Commands/SweepStaleCreations.php`, `routes/console.php`
- Create: `app/Console/Commands/PruneStylizations.php`
- Test: `tests/Feature/SweepStaleStylizationsTest.php`, `tests/Feature/PruneStylizationsTest.php`

**Interfaces:**
- Consumes: `StylizationService::markFailed` and `discard` (Task 4).
- Produces: `creations:sweep` also fails and refunds stylizations in queued/processing older than `stylizer.job_deadline_seconds + 120`; command `stylizations:prune` discards `ready`/`failed` stylizations older than `stylizer.retention_days` days; both scheduled (sweep every five minutes, prune daily).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/SweepStaleStylizationsTest.php`:

```php
<?php

use App\Enums\LedgerReason;
use App\Enums\StylizationStatus;
use App\Models\Style;
use App\Models\Stylization;
use App\Models\User;
use App\Services\Credits\CreditService;

beforeEach(function () {
    config(['stylizer.job_deadline_seconds' => 300]);
    $this->credits = app(CreditService::class);
    $this->user = User::factory()->create();
    $this->credits->grant($this->user, 10, LedgerReason::Signup);

    $this->make = function (StylizationStatus $status, int $ageSeconds) {
        $stylization = Stylization::factory()->create([
            'user_id' => $this->user->id,
            'style_id' => Style::factory(),
            'status' => $status,
        ]);
        $this->credits->spendForStylization($this->user, 1, $stylization);
        $stylization->forceFill(['created_at' => now()->subSeconds($ageSeconds)])->save();

        return $stylization;
    };
});

it('fails and refunds stale queued and processing previews exactly once', function () {
    $queued = ($this->make)(StylizationStatus::Queued, 500);
    $processing = ($this->make)(StylizationStatus::Processing, 500);

    $this->artisan('creations:sweep')->assertSuccessful();
    $this->artisan('creations:sweep')->assertSuccessful();

    expect($queued->fresh()->status)->toBe(StylizationStatus::Failed)
        ->and($processing->fresh()->status)->toBe(StylizationStatus::Failed)
        ->and($this->credits->balance($this->user))->toBe(10);
});

it('leaves fresh previews and previews waiting for approval alone', function () {
    $fresh = ($this->make)(StylizationStatus::Queued, 100);
    $within = ($this->make)(StylizationStatus::Processing, 400);
    $ready = ($this->make)(StylizationStatus::Ready, 5000);

    $this->artisan('creations:sweep')->assertSuccessful();

    expect($fresh->fresh()->status)->toBe(StylizationStatus::Queued)
        ->and($within->fresh()->status)->toBe(StylizationStatus::Processing)
        ->and($ready->fresh()->status)->toBe(StylizationStatus::Ready)
        ->and($this->credits->balance($this->user))->toBe(7);
});
```

`tests/Feature/PruneStylizationsTest.php`:

```php
<?php

use App\Enums\StylizationStatus;
use App\Models\Stylization;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config(['stylizer.retention_days' => 7]);

    $this->make = function (StylizationStatus $status, int $ageDays) {
        $stylization = Stylization::factory()->create([
            'status' => $status,
            'source_image_path' => 'stylizations/p/original.jpg',
            'result_image_path' => 'stylizations/p/result.png',
        ]);
        $stylization->forceFill(['created_at' => now()->subDays($ageDays)])->save();

        return $stylization;
    };
    Storage::disk('local')->put('stylizations/p/original.jpg', 'o');
    Storage::disk('local')->put('stylizations/p/result.png', 'r');
});

it('discards old ready and failed previews and deletes their files', function () {
    $ready = ($this->make)(StylizationStatus::Ready, 8);
    $failed = ($this->make)(StylizationStatus::Failed, 9);

    $this->artisan('stylizations:prune')->assertSuccessful();

    expect($ready->fresh()->status)->toBe(StylizationStatus::Discarded)
        ->and($ready->fresh()->source_image_path)->toBeNull()
        ->and($failed->fresh()->status)->toBe(StylizationStatus::Discarded);
    Storage::disk('local')->assertMissing('stylizations/p/original.jpg');
});

it('keeps recent, approved and working previews', function () {
    $recent = ($this->make)(StylizationStatus::Ready, 2);
    $approved = ($this->make)(StylizationStatus::Approved, 30);
    $working = ($this->make)(StylizationStatus::Processing, 30);

    $this->artisan('stylizations:prune')->assertSuccessful();

    expect($recent->fresh()->status)->toBe(StylizationStatus::Ready)
        ->and($approved->fresh()->status)->toBe(StylizationStatus::Approved)
        ->and($working->fresh()->status)->toBe(StylizationStatus::Processing);
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test tests/Feature/SweepStaleStylizationsTest.php tests/Feature/PruneStylizationsTest.php`
Expected: FAIL.

- [ ] **Step 3: Extend the sweeper (replace the file)**

`app/Console/Commands/SweepStaleCreations.php`:

```php
<?php

namespace App\Console\Commands;

use App\Enums\CreationStatus;
use App\Enums\StylizationStatus;
use App\Models\Creation;
use App\Models\Stylization;
use App\Services\CreationService;
use App\Services\StylizationService;
use Illuminate\Console\Command;

class SweepStaleCreations extends Command
{
    protected $signature = 'creations:sweep';

    protected $description = 'Fail and refund creations and previews stuck in queued/processing past their timeout';

    public function handle(CreationService $creations, StylizationService $stylizations): int
    {
        $failedCreations = $this->sweepCreations($creations);
        $failedPreviews = $this->sweepStylizations($stylizations);

        $this->info("Failed {$failedCreations} stale creation(s) and {$failedPreviews} stale preview(s).");

        return self::SUCCESS;
    }

    private function sweepCreations(CreationService $creations): int
    {
        $cutoff = now()->subSeconds((int) config('models.timeout_seconds') + 120);
        $failed = 0;

        Creation::query()
            ->whereIn('status', [CreationStatus::Queued->value, CreationStatus::Processing->value])
            ->where('created_at', '<', $cutoff)
            ->pluck('id')
            ->each(function (int $id) use ($creations, &$failed) {
                if ($creations->markFailed($id, 'Generation timed out.')) {
                    $failed++;
                }
            });

        return $failed;
    }

    private function sweepStylizations(StylizationService $stylizations): int
    {
        $cutoff = now()->subSeconds((int) config('stylizer.job_deadline_seconds') + 120);
        $failed = 0;

        Stylization::query()
            ->whereIn('status', [StylizationStatus::Queued->value, StylizationStatus::Processing->value])
            ->where('created_at', '<', $cutoff)
            ->pluck('id')
            ->each(function (int $id) use ($stylizations, &$failed) {
                if ($stylizations->markFailed($id, 'The preview timed out.')) {
                    $failed++;
                }
            });

        return $failed;
    }
}
```

The existing `tests/Feature/SweepStaleCreationsTest.php` asserts the command's printed output in at least one test; if it matches on "Failed N stale creation(s)." update that single expectation to the new sentence format and nothing else.

- [ ] **Step 4: Prune command and schedule**

`app/Console/Commands/PruneStylizations.php`:

```php
<?php

namespace App\Console\Commands;

use App\Enums\StylizationStatus;
use App\Models\Stylization;
use App\Services\StylizationService;
use Illuminate\Console\Command;

class PruneStylizations extends Command
{
    protected $signature = 'stylizations:prune';

    protected $description = 'Discard unapproved previews older than the retention period and delete their photos';

    public function handle(StylizationService $stylizations): int
    {
        $cutoff = now()->subDays((int) config('stylizer.retention_days'));
        $discarded = 0;

        Stylization::query()
            ->whereIn('status', [StylizationStatus::Ready->value, StylizationStatus::Failed->value])
            ->where('created_at', '<', $cutoff)
            ->get()
            ->each(function (Stylization $stylization) use ($stylizations, &$discarded) {
                if ($stylizations->discard($stylization)) {
                    $discarded++;
                }
            });

        $this->info("Discarded {$discarded} old preview(s).");

        return self::SUCCESS;
    }
}
```

In `routes/console.php` add below the existing schedule line:

```php
Schedule::command('stylizations:prune')->daily()->withoutOverlapping();
```

- [ ] **Step 5: Run tests and commit**

```bash
php artisan test tests/Feature/SweepStaleStylizationsTest.php tests/Feature/PruneStylizationsTest.php tests/Feature/SweepStaleCreationsTest.php
php artisan test
./vendor/bin/pint app routes tests/Feature/SweepStaleStylizationsTest.php tests/Feature/PruneStylizationsTest.php
php artisan creations:sweep && php artisan stylizations:prune
git add app routes tests
git commit -m "feat: sweep stale previews and prune old ones

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```
Expected: PASS; the two artisan commands run cleanly against the dev DB and report 0.

---

### Task 6: Approve, retry and 3D retry

**Files:**
- Modify: `app/Services/CreationService.php`, `app/Services/StylizationService.php`
- Test: `tests/Feature/StylizationApprovalTest.php`, `tests/Feature/CreationRetryTest.php`

**Interfaces:**
- Consumes: Task 4 service and job, `CreationService`, `GenerateCreation`.
- Produces:
  - `CreationService::makeCreation(User $user, Style $style, string $storedImagePath): Creation` (INSERT then charge in the caller's transaction; **does not dispatch**; throws `InsufficientCreditsException`)
  - `CreationService::dispatchGeneration(Creation $creation): void`
  - `CreationService::startFromImage(User $user, Style $style, string $storedImagePath): Creation` (own transaction, then dispatch)
  - `CreationService::retryFailed(User $user, Creation $creation): Creation` (failed creations only; copies the stored image; throws `StylizationNotReadyException` otherwise)
  - `StylizationService::approve(User $user, Stylization $stylization): Creation` (idempotent; only `ready`; charges the style's 3D price; deletes the original and result files after commit)
  - `StylizationService::retry(User $user, Stylization $stylization): Stylization` (from `ready` or `failed`; takes over the photo file; charges the restyle fee; old one `discarded`)
  - the existing `CreationService::submit()` stays for now (removed in Task 8).

- [ ] **Step 1: Write the failing approval tests**

`tests/Feature/StylizationApprovalTest.php`:

```php
<?php

use App\Enums\CreationStatus;
use App\Enums\LedgerReason;
use App\Enums\StylizationStatus;
use App\Exceptions\DailyLimitReachedException;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\StylizationNotReadyException;
use App\Jobs\GenerateCreation;
use App\Jobs\RestylePhoto;
use App\Models\Creation;
use App\Models\CreditLedgerEntry;
use App\Models\Style;
use App\Models\Stylization;
use App\Models\User;
use App\Services\Credits\CreditService;
use App\Services\StylizationService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    config(['stylizer.daily_limit' => 30, 'stylizer.enabled' => true, 'credits.restyle_cost' => 1]);

    $this->credits = app(CreditService::class);
    $this->service = app(StylizationService::class);
    $this->user = User::factory()->create();
    $this->credits->grant($this->user, 20, LedgerReason::Signup);
    $this->style = Style::factory()->create(['credit_cost' => 5]);

    $this->makeReady = function (array $attrs = []) {
        Storage::disk('local')->put('stylizations/r/original.jpg', fakeJpegBytes());
        Storage::disk('local')->put('stylizations/r/result.png', fakeJpegBytes(1024, 1536));
        $stylization = Stylization::factory()->create([
            'user_id' => $this->user->id,
            'style_id' => $this->style->id,
            'status' => StylizationStatus::Ready,
            'source_image_path' => 'stylizations/r/original.jpg',
            'result_image_path' => 'stylizations/r/result.png',
        ] + $attrs);
        $this->credits->spendForStylization($this->user, 1, $stylization);

        return $stylization;
    };
});

describe('approve', function () {
    it('creates a 3D creation from the restyled image and charges the 3D price', function () {
        $stylization = ($this->makeReady)();

        $creation = $this->service->approve($this->user, $stylization);

        expect($creation->status)->toBe(CreationStatus::Queued)
            ->and($creation->user_id)->toBe($this->user->id)
            ->and($creation->style_id)->toBe($this->style->id)
            ->and($creation->cost_credits)->toBe(5)
            ->and($this->credits->balance($this->user))->toBe(14); // 20 - 1 preview - 5 build
        Storage::disk('local')->assertExists($creation->source_image_path);
        expect($creation->source_image_path)->toStartWith('uploads/');
        Queue::assertPushed(GenerateCreation::class, fn ($job) => $job->creationId === $creation->id);

        $fresh = $stylization->fresh();
        expect($fresh->status)->toBe(StylizationStatus::Approved)
            ->and($fresh->creation_id)->toBe($creation->id)
            ->and($fresh->source_image_path)->toBeNull()
            ->and($fresh->result_image_path)->toBeNull();
        Storage::disk('local')->assertMissing('stylizations/r/original.jpg');
        Storage::disk('local')->assertMissing('stylizations/r/result.png');
    });

    it('is idempotent: a second approve returns the same creation and charges once', function () {
        $stylization = ($this->makeReady)();

        $first = $this->service->approve($this->user, $stylization);
        $second = $this->service->approve($this->user, $stylization->fresh());

        expect($second->id)->toBe($first->id)
            ->and(Creation::count())->toBe(1)
            ->and(CreditLedgerEntry::where('reason', LedgerReason::Generation)->count())->toBe(1);
        Queue::assertPushed(GenerateCreation::class, 1);
    });

    it('rejects previews that are not ready', function () {
        $stylization = ($this->makeReady)(['status' => StylizationStatus::Processing]);

        expect(fn () => $this->service->approve($this->user, $stylization))
            ->toThrow(StylizationNotReadyException::class);

        expect(Creation::count())->toBe(0);
    });

    it('keeps the preview and charges nothing when the user cannot afford the build', function () {
        $stylization = ($this->makeReady)();
        $this->style->update(['credit_cost' => 500]);

        expect(fn () => $this->service->approve($this->user, $stylization))
            ->toThrow(InsufficientCreditsException::class);

        expect($stylization->fresh()->status)->toBe(StylizationStatus::Ready)
            ->and($stylization->fresh()->result_image_path)->toBe('stylizations/r/result.png')
            ->and(Creation::count())->toBe(0)
            ->and($this->credits->balance($this->user))->toBe(19);
        Storage::disk('local')->assertExists('stylizations/r/result.png');
        expect(collect(Storage::disk('local')->allFiles())->filter(fn ($f) => str_starts_with($f, 'uploads/')))->toBeEmpty();
        Queue::assertNotPushed(GenerateCreation::class);
    });
});

describe('retry', function () {
    it('starts a new preview from the same photo, charges the fee and discards the old one', function () {
        $stylization = ($this->makeReady)();

        $new = $this->service->retry($this->user, $stylization);

        expect($new->id)->not->toBe($stylization->id)
            ->and($new->status)->toBe(StylizationStatus::Queued)
            ->and($new->style_id)->toBe($this->style->id)
            ->and($new->source_image_path)->toBe('stylizations/r/original.jpg')
            ->and($this->credits->balance($this->user))->toBe(18); // 20 - 1 first - 1 retry
        Queue::assertPushed(RestylePhoto::class, fn ($job) => $job->stylizationId === $new->id);

        $old = $stylization->fresh();
        expect($old->status)->toBe(StylizationStatus::Discarded)
            ->and($old->source_image_path)->toBeNull()
            ->and($old->result_image_path)->toBeNull();
        Storage::disk('local')->assertExists('stylizations/r/original.jpg');
        Storage::disk('local')->assertMissing('stylizations/r/result.png');
    });

    it('can retry a failed preview', function () {
        $stylization = ($this->makeReady)(['status' => StylizationStatus::Failed, 'result_image_path' => null]);

        $new = $this->service->retry($this->user, $stylization);

        expect($new->status)->toBe(StylizationStatus::Queued);
    });

    it('cannot retry a preview that is still being made or already approved', function () {
        $working = ($this->makeReady)(['status' => StylizationStatus::Queued]);
        $approved = ($this->makeReady)(['status' => StylizationStatus::Approved]);

        expect(fn () => $this->service->retry($this->user, $working))->toThrow(StylizationNotReadyException::class)
            ->and(fn () => $this->service->retry($this->user, $approved))->toThrow(StylizationNotReadyException::class);
    });

    it('respects the daily limit and the balance', function () {
        $stylization = ($this->makeReady)();

        config(['stylizer.daily_limit' => 1]);
        expect(fn () => $this->service->retry($this->user, $stylization))->toThrow(DailyLimitReachedException::class);

        config(['stylizer.daily_limit' => 30, 'credits.restyle_cost' => 500]);
        expect(fn () => $this->service->retry($this->user, $stylization))->toThrow(InsufficientCreditsException::class);

        expect($stylization->fresh()->status)->toBe(StylizationStatus::Ready);
    });
});
```

- [ ] **Step 2: Write the failing 3D retry tests**

`tests/Feature/CreationRetryTest.php`:

```php
<?php

use App\Enums\CreationStatus;
use App\Enums\LedgerReason;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\StylizationNotReadyException;
use App\Jobs\GenerateCreation;
use App\Models\Creation;
use App\Models\Style;
use App\Models\User;
use App\Services\CreationService;
use App\Services\Credits\CreditService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->credits = app(CreditService::class);
    $this->service = app(CreationService::class);
    $this->user = User::factory()->create();
    $this->credits->grant($this->user, 20, LedgerReason::Signup);
    $this->style = Style::factory()->create(['credit_cost' => 5]);

    $this->makeFailed = function (array $attrs = []) {
        Storage::disk('local')->put('uploads/old.jpg', fakeJpegBytes());

        return Creation::factory()->create([
            'user_id' => $this->user->id,
            'style_id' => $this->style->id,
            'status' => CreationStatus::Failed,
            'source_image_path' => 'uploads/old.jpg',
        ] + $attrs);
    };
});

it('starts a new creation from a copy of the stored image and charges the 3D price', function () {
    $failed = ($this->makeFailed)();

    $new = $this->service->retryFailed($this->user, $failed);

    expect($new->id)->not->toBe($failed->id)
        ->and($new->status)->toBe(CreationStatus::Queued)
        ->and($new->source_image_path)->not->toBe('uploads/old.jpg')
        ->and($this->credits->balance($this->user))->toBe(15);
    Storage::disk('local')->assertExists($new->source_image_path);
    Storage::disk('local')->assertExists('uploads/old.jpg');
    Queue::assertPushed(GenerateCreation::class, fn ($job) => $job->creationId === $new->id);
});

it('only retries failed creations', function () {
    $queued = ($this->makeFailed)(['status' => CreationStatus::Queued]);
    $succeeded = ($this->makeFailed)(['status' => CreationStatus::Succeeded]);

    expect(fn () => $this->service->retryFailed($this->user, $queued))->toThrow(StylizationNotReadyException::class)
        ->and(fn () => $this->service->retryFailed($this->user, $succeeded))->toThrow(StylizationNotReadyException::class);
});

it('cleans up and charges nothing when the user cannot afford the retry', function () {
    $failed = ($this->makeFailed)();
    $this->style->update(['credit_cost' => 500]);

    expect(fn () => $this->service->retryFailed($this->user, $failed))->toThrow(InsufficientCreditsException::class);

    expect(Creation::count())->toBe(1)
        ->and($this->credits->balance($this->user))->toBe(20)
        ->and(Storage::disk('local')->allFiles())->toBe(['uploads/old.jpg']);
});

it('creates a creation from a stored image without dispatching until asked', function () {
    Storage::disk('local')->put('uploads/x.jpg', fakeJpegBytes());

    $creation = $this->service->makeCreation($this->user, $this->style, 'uploads/x.jpg');

    expect($creation->status)->toBe(CreationStatus::Queued)
        ->and($this->credits->balance($this->user))->toBe(15);
    Queue::assertNothingPushed();

    $this->service->dispatchGeneration($creation);
    Queue::assertPushed(GenerateCreation::class, 1);
});
```

- [ ] **Step 3: Run to verify they fail**

Run: `php artisan test tests/Feature/StylizationApprovalTest.php tests/Feature/CreationRetryTest.php`
Expected: FAIL (methods missing).

- [ ] **Step 4: CreationService additions**

Add these methods to `app/Services/CreationService.php` (keep `submit` and `markFailed`; add `use App\Exceptions\StylizationNotReadyException;`):

```php
    /**
     * Insert a queued creation and charge its 3D price. Does NOT dispatch: callers that run
     * inside a larger transaction must dispatch only after it commits
     * ({@see dispatchGeneration()}). Only an INSERT runs before the charge, which takes the
     * user-row lock, so the transaction snapshot is taken after the lock.
     *
     * @throws InsufficientCreditsException
     */
    public function makeCreation(User $user, Style $style, string $storedImagePath): Creation
    {
        return DB::transaction(function () use ($user, $style, $storedImagePath) {
            $creation = Creation::create([
                'user_id' => $user->id,
                'style_id' => $style->id,
                'source_image_path' => $storedImagePath,
                'status' => CreationStatus::Queued,
                'cost_credits' => $style->credit_cost,
            ]);

            $this->credits->spend($user, $style->credit_cost, $creation);

            return $creation;
        });
    }

    public function dispatchGeneration(Creation $creation): void
    {
        GenerateCreation::dispatch($creation->id);
    }

    /** makeCreation in its own transaction, then dispatch. */
    public function startFromImage(User $user, Style $style, string $storedImagePath): Creation
    {
        $creation = $this->makeCreation($user, $style, $storedImagePath);

        $this->dispatchGeneration($creation);

        return $creation;
    }

    /**
     * Re-run the 3D build for a failed creation from a copy of its stored image.
     *
     * @throws StylizationNotReadyException when the creation is not failed or its image is gone
     * @throws InsufficientCreditsException
     */
    public function retryFailed(User $user, Creation $creation): Creation
    {
        $disk = Storage::disk('local');

        if ($creation->status !== CreationStatus::Failed || ! $creation->source_image_path || ! $disk->exists($creation->source_image_path)) {
            throw new StylizationNotReadyException('Only a failed creation with its image can be retried.');
        }

        $style = Style::findOrFail($creation->style_id);
        $copy = 'uploads/'.Str::uuid().'.jpg';
        $disk->copy($creation->source_image_path, $copy);

        try {
            return $this->startFromImage($user, $style, $copy);
        } catch (Throwable $e) {
            $disk->delete($copy);

            throw $e;
        }
    }
```

- [ ] **Step 5: StylizationService approve and retry**

Add to `app/Services/StylizationService.php` (add imports `App\Enums\StylizationStatus` is present; add `App\Exceptions\StylizationNotReadyException`, `App\Models\Creation`, and constructor dependency `CreationService $creations`; update the constructor to `__construct(private ImageSanitizer $sanitizer, private CreditService $credits, private CreationService $creations)`):

```php
    /**
     * Turn a ready preview into a 3D creation. Idempotent: approving twice returns the same
     * creation and charges once.
     *
     * Lock order is stylization row, then user row (inside makeCreation). Everything that
     * needs a plain SELECT (the style) is loaded BEFORE the transaction.
     *
     * @throws StylizationNotReadyException
     * @throws InsufficientCreditsException
     */
    public function approve(User $user, Stylization $stylization): Creation
    {
        $style = Style::findOrFail($stylization->style_id);
        $disk = Storage::disk('local');
        $newPath = null;
        $created = false;
        $leftovers = [];

        try {
            $creation = DB::transaction(function () use ($user, $stylization, $style, $disk, &$newPath, &$created, &$leftovers) {
                $locked = Stylization::whereKey($stylization->id)->lockForUpdate()->firstOrFail();

                if ($locked->status === StylizationStatus::Approved && $locked->creation_id) {
                    return Creation::findOrFail($locked->creation_id);
                }

                if ($locked->status !== StylizationStatus::Ready || ! $locked->result_image_path) {
                    throw new StylizationNotReadyException('This preview is not ready to be approved.');
                }

                // A clean, size-capped JPEG copy becomes the creation's own source image.
                $newPath = 'uploads/'.Str::uuid().'.jpg';
                $disk->put($newPath, $this->sanitizer->sanitize($disk->path($locked->result_image_path)));

                $creation = $this->creations->makeCreation($user, $style, $newPath);

                $leftovers = array_filter([$locked->source_image_path, $locked->result_image_path]);
                $locked->update([
                    'status' => StylizationStatus::Approved,
                    'creation_id' => $creation->id,
                    'source_image_path' => null,
                    'result_image_path' => null,
                ]);
                $created = true;

                return $creation;
            });
        } catch (Throwable $e) {
            if ($newPath) {
                $disk->delete($newPath);
            }

            throw $e;
        }

        if ($created) {
            $this->creations->dispatchGeneration($creation);
            $disk->delete($leftovers);
        }

        return $creation;
    }

    /**
     * Start a fresh preview from the same photo and discard this one. Allowed from ready or
     * failed. The new preview takes over the photo file. Charges the restyle fee again.
     *
     * @throws StylizationNotReadyException
     * @throws StylizerDisabledException
     * @throws DailyLimitReachedException
     * @throws InsufficientCreditsException
     */
    public function retry(User $user, Stylization $stylization): Stylization
    {
        $this->assertCanStart($user); // before the transaction, see assertCanStart()

        $resultToDelete = null;

        $new = DB::transaction(function () use ($user, $stylization, &$resultToDelete) {
            $locked = Stylization::whereKey($stylization->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [StylizationStatus::Ready, StylizationStatus::Failed], true) || ! $locked->source_image_path) {
                throw new StylizationNotReadyException('This preview cannot be retried.');
            }

            $new = Stylization::create([
                'user_id' => $user->id,
                'style_id' => $locked->style_id,
                'source_image_path' => $locked->source_image_path,
                'status' => StylizationStatus::Queued,
                'cost_credits' => (int) config('credits.restyle_cost'),
            ]);

            $this->credits->spendForStylization($user, $new->cost_credits, $new);

            $resultToDelete = $locked->result_image_path;
            $locked->update([
                'status' => StylizationStatus::Discarded,
                'source_image_path' => null,
                'result_image_path' => null,
            ]);

            return $new;
        });

        if ($resultToDelete) {
            Storage::disk('local')->delete($resultToDelete);
        }

        RestylePhoto::dispatch($new->id);

        return $new;
    }
```

Add the missing `use` lines at the top of `StylizationService.php`: `App\Models\Creation`, `App\Exceptions\StylizationNotReadyException`.

- [ ] **Step 6: Run tests and commit**

```bash
php artisan test tests/Feature/StylizationApprovalTest.php tests/Feature/CreationRetryTest.php
php artisan test
./vendor/bin/pint app tests/Feature/StylizationApprovalTest.php tests/Feature/CreationRetryTest.php
git add app tests
git commit -m "feat: approve and retry previews, retry failed 3D creations

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```
Expected: PASS. If the "idempotent approve" test fails because the second call hits `Style::findOrFail` etc., that is fine; the idempotent path must not charge or dispatch.


---

### Task 7: Stylization HTTP layer

**Files:**
- Create: `app/Http/Requests/StoreStylizationRequest.php`, `app/Http/Controllers/StylizationController.php`, `app/Http/Controllers/StylizationFileController.php`, `app/Http/Resources/StylizationResource.php`, `app/Policies/StylizationPolicy.php`
- Create (placeholder, replaced in Task 9): `resources/js/pages/stylizations/Show.vue` containing exactly `<template><div /></template>`
- Modify: `routes/web.php`
- Test: `tests/Feature/StylizationHttpTest.php`

**Interfaces:**
- Consumes: `StylizationService` (Tasks 4 and 6), `CreditService`, `StylizationStatus`.
- Produces routes (all behind `auth`): `POST /stylizations` (`stylizations.store`, `throttle:generate`), `GET /stylizations/{stylization}` (`stylizations.show`), `POST /stylizations/{stylization}/approve` (`stylizations.approve`, throttled), `POST /stylizations/{stylization}/retry` (`stylizations.retry`, throttled), `DELETE /stylizations/{stylization}` (`stylizations.destroy`), `GET /stylizations/{stylization}/files/{type}` (`stylizations.files`, type in `original|result`).
- Inertia props for `stylizations/Show`: `{ stylization: StylizationPayload, balance: int, restyle_cost: int }` where `StylizationPayload = { id, status, error, cost_credits, created_at, creation_id, style: {id, name, subject, look, credit_cost}, urls: {original|null, result|null} }`. An approved stylization redirects to its creation page instead of rendering.
- Validation/error keys: `photo` and `style_id` on store; `approve` and `retry` on those actions.
- The old `POST /creations` route stays until Task 8.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/StylizationHttpTest.php`:

```php
<?php

use App\Enums\LedgerReason;
use App\Enums\StylizationStatus;
use App\Jobs\RestylePhoto;
use App\Models\Creation;
use App\Models\Style;
use App\Models\Stylization;
use App\Models\User;
use App\Services\Credits\CreditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    config(['credits.restyle_cost' => 1, 'stylizer.enabled' => true, 'stylizer.daily_limit' => 30]);

    $this->credits = app(CreditService::class);
    $this->user = User::factory()->create();
    $this->credits->grant($this->user, 20, LedgerReason::Signup);
    $this->other = User::factory()->create();
    $this->style = Style::factory()->create(['credit_cost' => 5]);

    $this->makeReady = function (array $attrs = []) {
        Storage::disk('local')->put('stylizations/h/original.jpg', fakeJpegBytes());
        Storage::disk('local')->put('stylizations/h/result.png', fakeJpegBytes(1024, 1536));
        $stylization = Stylization::factory()->create([
            'user_id' => $this->user->id,
            'style_id' => $this->style->id,
            'status' => StylizationStatus::Ready,
            'source_image_path' => 'stylizations/h/original.jpg',
            'result_image_path' => 'stylizations/h/result.png',
        ] + $attrs);
        $this->credits->spendForStylization($this->user, 1, $stylization);

        return $stylization;
    };
});

it('requires authentication', function () {
    $this->post('/stylizations')->assertRedirect('/login');
    $this->get('/stylizations/1')->assertRedirect('/login');
    $this->post('/stylizations/1/approve')->assertRedirect('/login');
    $this->post('/stylizations/1/retry')->assertRedirect('/login');
    $this->delete('/stylizations/1')->assertRedirect('/login');
});

describe('store', function () {
    it('creates a preview, charges the fee and queues the restyle', function () {
        $response = $this->actingAs($this->user)->post('/stylizations', [
            'photo' => UploadedFile::fake()->image('me.jpg', 800, 800),
            'style_id' => $this->style->id,
        ]);

        $stylization = Stylization::firstOrFail();
        $response->assertRedirect("/stylizations/{$stylization->id}");
        expect($stylization->status)->toBe(StylizationStatus::Queued)
            ->and($stylization->user_id)->toBe($this->user->id)
            ->and($this->credits->balance($this->user))->toBe(19);
        Storage::disk('local')->assertExists($stylization->source_image_path);
        Queue::assertPushed(RestylePhoto::class, fn ($job) => $job->stylizationId === $stylization->id);
    });

    it('validates the photo and the style', function (array $payload, string $field) {
        $this->actingAs($this->user)->post('/stylizations', $payload + [
            'photo' => UploadedFile::fake()->image('me.jpg', 800, 800),
            'style_id' => $this->style->id,
        ])->assertSessionHasErrors($field);

        expect(Stylization::count())->toBe(0);
    })->with([
        'not an image' => [['photo' => UploadedFile::fake()->create('x.txt', 10, 'text/plain')], 'photo'],
        'too small' => [['photo' => UploadedFile::fake()->image('s.jpg', 100, 100)], 'photo'],
        'too many pixels on one side' => [['photo' => UploadedFile::fake()->image('b.jpg', 8001, 600)], 'photo'],
        'missing style' => [['style_id' => null], 'style_id'],
        'unknown style' => [['style_id' => 9999], 'style_id'],
    ]);

    it('rejects inactive styles', function () {
        $inactive = Style::factory()->create(['active' => false]);

        $this->actingAs($this->user)->post('/stylizations', [
            'photo' => UploadedFile::fake()->image('me.jpg', 800, 800),
            'style_id' => $inactive->id,
        ])->assertSessionHasErrors('style_id');
    });

    it('reports an unaffordable preview on the style field and creates nothing', function () {
        config(['credits.restyle_cost' => 50]);

        $this->actingAs($this->user)->post('/stylizations', [
            'photo' => UploadedFile::fake()->image('me.jpg', 800, 800),
            'style_id' => $this->style->id,
        ])->assertSessionHasErrors('style_id');

        expect(Stylization::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('explains the kill switch and the daily limit on the photo field', function () {
        config(['stylizer.enabled' => false]);
        $payload = fn () => ['photo' => UploadedFile::fake()->image('me.jpg', 800, 800), 'style_id' => $this->style->id];

        $this->actingAs($this->user)->post('/stylizations', $payload())
            ->assertSessionHasErrors(['photo' => 'Previews are temporarily unavailable. Please try again later.']);

        config(['stylizer.enabled' => true, 'stylizer.daily_limit' => 0]);
        $this->actingAs($this->user)->post('/stylizations', $payload())
            ->assertSessionHasErrors(['photo' => "You've reached today's preview limit. Try again tomorrow."]);
    });
});

describe('show', function () {
    it('shows a preview to its owner with file urls, the balance and the fee', function () {
        $stylization = ($this->makeReady)();

        $this->actingAs($this->user)->get("/stylizations/{$stylization->id}")->assertInertia(fn (Assert $page) => $page
            ->component('stylizations/Show')
            ->where('stylization.id', $stylization->id)
            ->where('stylization.status', 'ready')
            ->where('stylization.style.credit_cost', 5)
            ->where('stylization.urls.original', "/stylizations/{$stylization->id}/files/original")
            ->where('stylization.urls.result', "/stylizations/{$stylization->id}/files/result")
            ->where('balance', 19)
            ->where('restyle_cost', 1));
    });

    it('sends an approved preview on to its creation', function () {
        $creation = Creation::factory()->create(['user_id' => $this->user->id]);
        $stylization = ($this->makeReady)(['status' => StylizationStatus::Approved, 'creation_id' => $creation->id]);

        $this->actingAs($this->user)->get("/stylizations/{$stylization->id}")
            ->assertRedirect("/creations/{$creation->id}");
    });

    it('forbids other users', function () {
        $stylization = ($this->makeReady)();

        $this->actingAs($this->other)->get("/stylizations/{$stylization->id}")->assertForbidden();
    });
});

describe('files', function () {
    it('serves both images to the owner and 404s when a file is gone', function () {
        $stylization = ($this->makeReady)();

        $this->actingAs($this->user)->get("/stylizations/{$stylization->id}/files/original")->assertOk();
        $this->actingAs($this->user)->get("/stylizations/{$stylization->id}/files/result")->assertOk();

        $stylization->update(['result_image_path' => null]);
        $this->actingAs($this->user)->get("/stylizations/{$stylization->id}/files/result")->assertNotFound();
    });

    it('forbids other users and unknown types', function () {
        $stylization = ($this->makeReady)();

        $this->actingAs($this->other)->get("/stylizations/{$stylization->id}/files/original")->assertForbidden();
        $this->actingAs($this->user)->get("/stylizations/{$stylization->id}/files/model")->assertNotFound();
    });
});

describe('approve', function () {
    it('builds the 3D model and sends the customer to the creation page', function () {
        $stylization = ($this->makeReady)();

        $response = $this->actingAs($this->user)->post("/stylizations/{$stylization->id}/approve");

        $creation = Creation::firstOrFail();
        $response->assertRedirect("/creations/{$creation->id}");
        expect($stylization->fresh()->status)->toBe(StylizationStatus::Approved)
            ->and($this->credits->balance($this->user))->toBe(14);
    });

    it('is forbidden for other users', function () {
        $stylization = ($this->makeReady)();

        $this->actingAs($this->other)->post("/stylizations/{$stylization->id}/approve")->assertForbidden();
        expect(Creation::count())->toBe(0);
    });

    it('keeps the preview and explains when the build is unaffordable', function () {
        $stylization = ($this->makeReady)();
        $this->style->update(['credit_cost' => 500]);

        $this->actingAs($this->user)->post("/stylizations/{$stylization->id}/approve")->assertSessionHasErrors('approve');

        expect($stylization->fresh()->status)->toBe(StylizationStatus::Ready)->and(Creation::count())->toBe(0);
    });

    it('explains when the preview cannot be approved', function () {
        $stylization = ($this->makeReady)(['status' => StylizationStatus::Failed]);

        $this->actingAs($this->user)->post("/stylizations/{$stylization->id}/approve")->assertSessionHasErrors('approve');
    });
});

describe('retry', function () {
    it('starts a new preview and sends the customer to it', function () {
        $stylization = ($this->makeReady)();

        $response = $this->actingAs($this->user)->post("/stylizations/{$stylization->id}/retry");

        $new = Stylization::where('id', '!=', $stylization->id)->firstOrFail();
        $response->assertRedirect("/stylizations/{$new->id}");
        expect($stylization->fresh()->status)->toBe(StylizationStatus::Discarded);
        Queue::assertPushed(RestylePhoto::class, fn ($job) => $job->stylizationId === $new->id);
    });

    it('is forbidden for other users and explains an unaffordable retry', function () {
        $stylization = ($this->makeReady)();

        $this->actingAs($this->other)->post("/stylizations/{$stylization->id}/retry")->assertForbidden();

        config(['credits.restyle_cost' => 500]);
        $this->actingAs($this->user)->post("/stylizations/{$stylization->id}/retry")->assertSessionHasErrors('retry');
    });
});

describe('destroy', function () {
    it('discards a ready preview and goes back to the list', function () {
        $stylization = ($this->makeReady)();

        $this->actingAs($this->user)->delete("/stylizations/{$stylization->id}")->assertRedirect('/creations');

        expect($stylization->fresh()->status)->toBe(StylizationStatus::Discarded);
        Storage::disk('local')->assertMissing('stylizations/h/original.jpg');
    });

    it('refuses to discard a preview that is still being made, and other users', function () {
        $working = ($this->makeReady)(['status' => StylizationStatus::Processing]);

        $this->actingAs($this->user)->delete("/stylizations/{$working->id}")->assertStatus(409);
        $this->actingAs($this->other)->delete("/stylizations/{$working->id}")->assertForbidden();
    });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/StylizationHttpTest.php`
Expected: FAIL (routes missing).

- [ ] **Step 3: Request, resource, policy**

`app/Http/Requests/StoreStylizationRequest.php` is the existing `StoreCreationRequest` with the class renamed (copy the file, keep every rule, the megapixel cap and the messages unchanged):

```bash
cp app/Http/Requests/StoreCreationRequest.php app/Http/Requests/StoreStylizationRequest.php
```
Then change the class line in the copy to `class StoreStylizationRequest extends FormRequest`. (The original is deleted in Task 8.)

`app/Http/Resources/StylizationResource.php`:

```php
<?php

namespace App\Http\Resources;

use App\Models\Stylization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Stylization */
class StylizationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $file = fn (string $type) => route(
            'stylizations.files',
            ['stylization' => $this->id, 'type' => $type],
            absolute: false,
        );

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'error' => $this->error,
            'cost_credits' => $this->cost_credits,
            'created_at' => $this->created_at->toIso8601String(),
            'creation_id' => $this->creation_id,
            'style' => [
                'id' => $this->style->id,
                'name' => $this->style->name,
                'subject' => $this->style->subject->value,
                'look' => $this->style->look,
                'credit_cost' => $this->style->credit_cost,
            ],
            'urls' => [
                'original' => $this->source_image_path ? $file('original') : null,
                'result' => $this->result_image_path ? $file('result') : null,
            ],
        ];
    }
}
```

`app/Policies/StylizationPolicy.php` (ownership only; the state rules live in the service so a repeated approve stays idempotent instead of becoming a 403):

```php
<?php

namespace App\Policies;

use App\Models\Stylization;
use App\Models\User;

class StylizationPolicy
{
    public function view(User $user, Stylization $stylization): bool
    {
        return $stylization->user_id === $user->id;
    }

    public function approve(User $user, Stylization $stylization): bool
    {
        return $this->view($user, $stylization);
    }

    public function retry(User $user, Stylization $stylization): bool
    {
        return $this->view($user, $stylization);
    }

    public function discard(User $user, Stylization $stylization): bool
    {
        return $this->view($user, $stylization);
    }
}
```

- [ ] **Step 4: Controllers**

`app/Http/Controllers/StylizationController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\StylizationStatus;
use App\Exceptions\DailyLimitReachedException;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\InvalidImageException;
use App\Exceptions\StylizationNotReadyException;
use App\Exceptions\StylizerDisabledException;
use App\Http\Requests\StoreStylizationRequest;
use App\Http\Resources\StylizationResource;
use App\Models\Style;
use App\Models\Stylization;
use App\Services\Credits\CreditService;
use App\Services\StylizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StylizationController extends Controller
{
    public function store(StoreStylizationRequest $request, StylizationService $stylizations): RedirectResponse
    {
        $style = Style::active()->findOrFail($request->integer('style_id'));

        try {
            $stylization = $stylizations->create($request->user(), $request->file('photo'), $style);
        } catch (InsufficientCreditsException $e) {
            throw ValidationException::withMessages([
                'style_id' => "Not enough credits for a preview: it costs {$e->required} and you have {$e->balance}.",
            ]);
        } catch (InvalidImageException) {
            throw ValidationException::withMessages(['photo' => 'We could not read that image. Try another photo.']);
        } catch (StylizerDisabledException) {
            throw ValidationException::withMessages(['photo' => 'Previews are temporarily unavailable. Please try again later.']);
        } catch (DailyLimitReachedException) {
            throw ValidationException::withMessages(['photo' => "You've reached today's preview limit. Try again tomorrow."]);
        }

        return redirect()->route('stylizations.show', $stylization);
    }

    public function show(Request $request, Stylization $stylization, CreditService $credits): Response|RedirectResponse
    {
        Gate::authorize('view', $stylization);

        if ($stylization->status === StylizationStatus::Approved && $stylization->creation_id) {
            return redirect()->route('creations.show', $stylization->creation_id);
        }

        return Inertia::render('stylizations/Show', [
            'stylization' => StylizationResource::make($stylization->load('style'))->resolve(),
            'balance' => $credits->balance($request->user()),
            'restyle_cost' => (int) config('credits.restyle_cost'),
        ]);
    }

    public function approve(Request $request, Stylization $stylization, StylizationService $stylizations): RedirectResponse
    {
        Gate::authorize('approve', $stylization);

        try {
            $creation = $stylizations->approve($request->user(), $stylization);
        } catch (InsufficientCreditsException $e) {
            throw ValidationException::withMessages([
                'approve' => "Not enough credits: building the 3D model costs {$e->required} and you have {$e->balance}.",
            ]);
        } catch (StylizationNotReadyException) {
            throw ValidationException::withMessages(['approve' => 'This preview can no longer be approved.']);
        }

        return redirect()->route('creations.show', $creation);
    }

    public function retry(Request $request, Stylization $stylization, StylizationService $stylizations): RedirectResponse
    {
        Gate::authorize('retry', $stylization);

        try {
            $new = $stylizations->retry($request->user(), $stylization);
        } catch (InsufficientCreditsException $e) {
            throw ValidationException::withMessages([
                'retry' => "Not enough credits for another preview: it costs {$e->required} and you have {$e->balance}.",
            ]);
        } catch (StylizationNotReadyException) {
            throw ValidationException::withMessages(['retry' => 'This preview cannot be retried.']);
        } catch (StylizerDisabledException) {
            throw ValidationException::withMessages(['retry' => 'Previews are temporarily unavailable. Please try again later.']);
        } catch (DailyLimitReachedException) {
            throw ValidationException::withMessages(['retry' => "You've reached today's preview limit. Try again tomorrow."]);
        }

        return redirect()->route('stylizations.show', $new);
    }

    public function destroy(Stylization $stylization, StylizationService $stylizations): RedirectResponse
    {
        Gate::authorize('discard', $stylization);

        abort_unless($stylizations->discard($stylization), 409, 'This preview cannot be discarded right now.');

        return redirect()->route('creations.index');
    }
}
```

`app/Http/Controllers/StylizationFileController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Stylization;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StylizationFileController extends Controller
{
    public function __invoke(Stylization $stylization, string $type): StreamedResponse
    {
        Gate::authorize('view', $stylization);

        $path = match ($type) {
            'original' => $stylization->source_image_path,
            'result' => $stylization->result_image_path,
        };

        $disk = Storage::disk('local');
        abort_if(! $path || ! $disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
```

- [ ] **Step 5: Routes and placeholder page**

In `routes/web.php` add the import lines `use App\Http\Controllers\StylizationController;` and `use App\Http\Controllers\StylizationFileController;` and, inside the `auth` group (after the creations routes):

```php
    Route::post('stylizations', [StylizationController::class, 'store'])->middleware('throttle:generate')->name('stylizations.store');
    Route::get('stylizations/{stylization}', [StylizationController::class, 'show'])->name('stylizations.show');
    Route::post('stylizations/{stylization}/approve', [StylizationController::class, 'approve'])->middleware('throttle:generate')->name('stylizations.approve');
    Route::post('stylizations/{stylization}/retry', [StylizationController::class, 'retry'])->middleware('throttle:generate')->name('stylizations.retry');
    Route::delete('stylizations/{stylization}', [StylizationController::class, 'destroy'])->name('stylizations.destroy');
    Route::get('stylizations/{stylization}/files/{type}', StylizationFileController::class)
        ->whereIn('type', ['original', 'result'])
        ->name('stylizations.files');
```

Create `resources/js/pages/stylizations/Show.vue` with exactly `<template><div /></template>` (Inertia's page-exists check needs the file; Task 9 overwrites it).

- [ ] **Step 6: Run tests and commit**

```bash
php artisan test tests/Feature/StylizationHttpTest.php
php artisan test
./vendor/bin/pint app routes tests/Feature/StylizationHttpTest.php
git add app routes resources tests
git commit -m "feat: add preview endpoints, files, policy and resource

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```
Expected: PASS. If the `too many pixels on one side` dataset is flaky with `UploadedFile::fake()->image('b.jpg', 8001, 600)` (GD memory), use a header-only approach consistent with the existing megapixel tests in `tests/Feature/CreationFlowTest.php`.

---

### Task 8: Creations changes and the new end-to-end test

**Files:**
- Modify: `app/Http/Controllers/CreationController.php`, `app/Policies/CreationPolicy.php`, `app/Services/CreationService.php`, `routes/web.php`
- Delete: `app/Http/Requests/StoreCreationRequest.php`
- Modify (rewrite): `tests/Feature/CreationFlowTest.php`
- Test: `tests/Feature/CreationRetryHttpTest.php`

**Interfaces:**
- Consumes: Tasks 4 to 7.
- Produces: `GET /create` props gain `restyle_cost`; `GET /creations` props gain `previews: StylizationPayload[]` (the user's `ready` stylizations, newest first); `POST /creations/{creation}/retry` (`creations.retry`, throttled, owner and failed only); **the public `POST /creations` route, `CreationController::store`, `StoreCreationRequest` and `CreationService::submit()` are removed**, so the preview step cannot be skipped. `CreationPolicy::retry(User, Creation): bool`.

- [ ] **Step 1: Rewrite the flow tests and add the retry HTTP tests**

Replace the whole of `tests/Feature/CreationFlowTest.php` with:

```php
<?php

use App\Enums\CreationStatus;
use App\Enums\LedgerReason;
use App\Enums\StylizationStatus;
use App\Models\Creation;
use App\Models\Style;
use App\Models\Stylization;
use App\Models\User;
use App\Services\Credits\CreditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    config(['credits.restyle_cost' => 1, 'stylizer.enabled' => true, 'stylizer.daily_limit' => 30]);
    $this->user = User::factory()->create();
    app(CreditService::class)->grant($this->user, 20, LedgerReason::Signup);
    $this->style = Style::factory()->create(['credit_cost' => 5]);
});

it('requires authentication', function () {
    $this->get('/create')->assertRedirect('/login');
});

it('lists active styles, the balance and the preview fee on the create page', function () {
    Style::factory()->create(['active' => false]);

    $this->actingAs($this->user)->get('/create')->assertInertia(fn (Assert $page) => $page
        ->component('Create')
        ->has('styles', 1)
        ->where('styles.0.id', $this->style->id)
        ->where('balance', 20)
        ->where('restyle_cost', 1));
});

it('has no way to skip the preview step', function () {
    $this->actingAs($this->user)->post('/creations', [
        'photo' => UploadedFile::fake()->image('me.jpg', 800, 800),
        'style_id' => $this->style->id,
    ])->assertStatus(405);

    expect(Creation::count())->toBe(0);
});

it('runs the whole two-stage flow end to end with the mock providers', function () {
    config([
        'queue.default' => 'sync',
        'stylizer.mock.fail_rate' => 0,
        'models.mock.delay_seconds' => 0,
        'models.mock.fail_rate' => 0,
    ]);

    // Stage 1: upload, restyle (sync queue runs the job immediately).
    $this->actingAs($this->user)->post('/stylizations', [
        'photo' => UploadedFile::fake()->image('me.jpg', 800, 800),
        'style_id' => $this->style->id,
    ])->assertRedirect();

    $stylization = Stylization::firstOrFail();
    expect($stylization->status)->toBe(StylizationStatus::Ready)
        ->and(app(CreditService::class)->balance($this->user))->toBe(19);
    Storage::disk('local')->assertExists($stylization->result_image_path);

    // Stage 2: approve, Meshy stand-in builds the model (sync queue).
    $this->actingAs($this->user)->post("/stylizations/{$stylization->id}/approve")->assertRedirect();

    $creation = Creation::firstOrFail();
    expect($creation->status)->toBe(CreationStatus::Succeeded)
        ->and($creation->cost_credits)->toBe(5)
        ->and(app(CreditService::class)->balance($this->user))->toBe(14)
        ->and($stylization->fresh()->status)->toBe(StylizationStatus::Approved);
    Storage::disk('local')->assertExists($creation->model_path);
});

it('refunds the preview fee when the restyle is refused, and the build fee when 3D fails', function () {
    config(['queue.default' => 'sync', 'stylizer.mock.fail_rate' => 1]);

    $this->actingAs($this->user)->post('/stylizations', [
        'photo' => UploadedFile::fake()->image('me.jpg', 800, 800),
        'style_id' => $this->style->id,
    ]);

    expect(Stylization::firstOrFail()->status)->toBe(StylizationStatus::Failed)
        ->and(app(CreditService::class)->balance($this->user))->toBe(20);

    config(['stylizer.mock.fail_rate' => 0, 'models.mock.delay_seconds' => 0, 'models.mock.fail_rate' => 1]);

    $this->actingAs($this->user)->post('/stylizations', [
        'photo' => UploadedFile::fake()->image('me.jpg', 800, 800),
        'style_id' => $this->style->id,
    ]);
    $ready = Stylization::where('status', StylizationStatus::Ready->value)->firstOrFail();
    $this->actingAs($this->user)->post("/stylizations/{$ready->id}/approve");

    expect(Creation::firstOrFail()->status)->toBe(CreationStatus::Failed)
        ->and(app(CreditService::class)->balance($this->user))->toBe(19); // only the preview fee stays spent
});

it('lists previews waiting for approval above the creations', function () {
    Storage::disk('local')->put('stylizations/i/original.jpg', fakeJpegBytes());
    Storage::disk('local')->put('stylizations/i/result.png', fakeJpegBytes());
    $ready = Stylization::factory()->create([
        'user_id' => $this->user->id,
        'style_id' => $this->style->id,
        'status' => StylizationStatus::Ready,
        'source_image_path' => 'stylizations/i/original.jpg',
        'result_image_path' => 'stylizations/i/result.png',
    ]);
    Stylization::factory()->create(['user_id' => $this->user->id, 'style_id' => $this->style->id, 'status' => StylizationStatus::Failed]);
    Stylization::factory()->create(['status' => StylizationStatus::Ready]); // someone else's

    $this->actingAs($this->user)->get('/creations')->assertInertia(fn (Assert $page) => $page
        ->component('creations/Index')
        ->has('previews', 1)
        ->where('previews.0.id', $ready->id)
        ->where('previews.0.urls.result', "/stylizations/{$ready->id}/files/result"));
});
```

`tests/Feature/CreationRetryHttpTest.php`:

```php
<?php

use App\Enums\CreationStatus;
use App\Enums\LedgerReason;
use App\Jobs\GenerateCreation;
use App\Models\Creation;
use App\Models\Style;
use App\Models\User;
use App\Services\Credits\CreditService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->credits = app(CreditService::class);
    $this->user = User::factory()->create();
    $this->credits->grant($this->user, 20, LedgerReason::Signup);
    $this->other = User::factory()->create();
    $this->style = Style::factory()->create(['credit_cost' => 5]);

    $this->makeCreation = function (CreationStatus $status) {
        Storage::disk('local')->put('uploads/c.jpg', fakeJpegBytes());

        return Creation::factory()->create([
            'user_id' => $this->user->id,
            'style_id' => $this->style->id,
            'status' => $status,
            'source_image_path' => 'uploads/c.jpg',
        ]);
    };
});

it('requires authentication', function () {
    $this->post('/creations/1/retry')->assertRedirect('/login');
});

it('retries a failed creation and sends the customer to the new one', function () {
    $failed = ($this->makeCreation)(CreationStatus::Failed);

    $response = $this->actingAs($this->user)->post("/creations/{$failed->id}/retry");

    $new = Creation::where('id', '!=', $failed->id)->firstOrFail();
    $response->assertRedirect("/creations/{$new->id}");
    expect($this->credits->balance($this->user))->toBe(15);
    Queue::assertPushed(GenerateCreation::class, fn ($job) => $job->creationId === $new->id);
});

it('is forbidden for other users and for creations that did not fail', function () {
    $failed = ($this->makeCreation)(CreationStatus::Failed);
    $succeeded = ($this->makeCreation)(CreationStatus::Succeeded);

    $this->actingAs($this->other)->post("/creations/{$failed->id}/retry")->assertForbidden();
    $this->actingAs($this->user)->post("/creations/{$succeeded->id}/retry")->assertForbidden();
    expect(Creation::count())->toBe(2);
});

it('explains an unaffordable retry', function () {
    $failed = ($this->makeCreation)(CreationStatus::Failed);
    $this->style->update(['credit_cost' => 500]);

    $this->actingAs($this->user)->post("/creations/{$failed->id}/retry")->assertSessionHasErrors('retry');
    expect(Creation::count())->toBe(1);
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test tests/Feature/CreationFlowTest.php tests/Feature/CreationRetryHttpTest.php`
Expected: FAIL (props, route and retry missing; `POST /creations` still exists so the "no way to skip" test fails).

- [ ] **Step 3: Controller, policy, routes**

In `app/Http/Controllers/CreationController.php`: delete the `store` method and its now-unused imports (`InvalidImageException`, `StoreCreationRequest`, `CreationService` stays), then:

1. In `create()` add `'restyle_cost' => (int) config('credits.restyle_cost'),` to the props array (after `'balance'`).
2. Replace `index()` with:

```php
    public function index(Request $request): Response
    {
        $creations = Creation::with('style')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(60)
            ->get();

        $previews = Stylization::with('style')
            ->where('user_id', $request->user()->id)
            ->where('status', StylizationStatus::Ready->value)
            ->latest()
            ->get();

        return Inertia::render('creations/Index', [
            'creations' => $creations->map(fn (Creation $c) => CreationResource::make($c)->resolve())->values(),
            'previews' => $previews->map(fn (Stylization $s) => StylizationResource::make($s)->resolve())->values(),
        ]);
    }
```

3. Add:

```php
    public function retry(Request $request, Creation $creation, CreationService $creations): RedirectResponse
    {
        Gate::authorize('retry', $creation);

        try {
            $new = $creations->retryFailed($request->user(), $creation);
        } catch (InsufficientCreditsException $e) {
            throw ValidationException::withMessages([
                'retry' => "Not enough credits: building the 3D model costs {$e->required} and you have {$e->balance}.",
            ]);
        } catch (StylizationNotReadyException) {
            throw ValidationException::withMessages(['retry' => 'This creation cannot be retried.']);
        }

        return redirect()->route('creations.show', $new);
    }
```

with imports `App\Enums\StylizationStatus`, `App\Exceptions\StylizationNotReadyException`, `App\Http\Resources\StylizationResource`, `App\Models\Stylization`.

In `app/Policies/CreationPolicy.php` add (import `App\Enums\CreationStatus`):

```php
    /** Only a failed creation can be re-run from its stored image. */
    public function retry(User $user, Creation $creation): bool
    {
        return $this->view($user, $creation) && $creation->status === CreationStatus::Failed;
    }
```

In `routes/web.php` remove the `Route::post('creations', ...)->name('creations.store')` line and add:

```php
    Route::post('creations/{creation}/retry', [CreationController::class, 'retry'])->middleware('throttle:generate')->name('creations.retry');
```
Make sure `GET creations` keeps working (`Route::get('creations', ...)` stays).

- [ ] **Step 4: Remove the dead upload path**

```bash
git rm app/Http/Requests/StoreCreationRequest.php
```
In `app/Services/CreationService.php` delete `submit()`, the `ImageSanitizer` constructor dependency (the constructor becomes `public function __construct(private CreditService $credits) {}`) and any imports left unused (`UploadedFile`, `InvalidImageException`, `Str` is still used by `retryFailed`). Search the whole repo for other uses of `CreationService::submit` or `new CreationService(` and fix them (tests and `StylizationService` resolve it through the container, so none should need changes).

- [ ] **Step 5: Run tests and commit**

```bash
php artisan test tests/Feature/CreationFlowTest.php tests/Feature/CreationRetryHttpTest.php
php artisan test
./vendor/bin/pint app routes tests/Feature/CreationFlowTest.php tests/Feature/CreationRetryHttpTest.php
git add -A app routes tests
git commit -m "feat: route uploads through previews and add 3D retry

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```
Expected: full suite PASS, including the end-to-end test through both stages. `CreationAccessTest` must still pass unchanged.

---

### Task 9: Frontend: polling composable, Create page and the preview page

**Files:**
- Create: `resources/js/composables/usePolling.ts`, `resources/js/pages/stylizations/Show.vue` (overwrites the placeholder)
- Modify: `resources/js/pages/Create.vue`

**Interfaces:**
- Consumes: `/stylizations` routes and `StylizationPayload` (Task 7); `/create` props `{ styles, balance, restyle_cost }` (Task 8).
- Produces: `usePolling({ only, active, intervalMs?, maxMs? }): { failed: Ref<boolean>; slow: Ref<boolean>; retry(): void; start(): void }` for Task 10 to reuse.

There are no JS unit tests. Verification: `npx vue-tsc --noEmit` (only the 2 known TS2688 errors), `npm run build`, `npx eslint` clean on the files you changed, and a **runtime browser check** (Step 4).

- [ ] **Step 1: The polling composable**

`resources/js/composables/usePolling.ts`:

```ts
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

type PollingOptions = {
    /** Props to reload on every tick, e.g. ['stylization']. */
    only: string[];
    /** Polling continues only while this returns true. */
    active: () => boolean;
    intervalMs?: number;
    /** Stop (and set `slow`) after this long. */
    maxMs?: number;
};

/**
 * Re-fetches some Inertia props every few seconds while `active()` is true. Unlike a bare
 * setInterval it stops on request errors (404, 419, 5xx, offline) instead of looping on
 * Inertia's error modal, and stops after `maxMs` so a stuck job does not poll forever.
 */
export function usePolling(options: PollingOptions) {
    const { only, active, intervalMs = 3000, maxMs = 12 * 60 * 1000 } = options;
    const failed = ref(false);
    const slow = ref(false);

    let timer: ReturnType<typeof setInterval> | null = null;
    let startedAt = 0;
    let removers: Array<() => void> = [];

    function stop() {
        if (timer) clearInterval(timer);
        timer = null;
    }

    function start() {
        if (timer) return;
        startedAt = Date.now();
        timer = setInterval(() => {
            if (!active()) return stop();
            if (Date.now() - startedAt > maxMs) {
                slow.value = true;
                return stop();
            }
            router.reload({ only });
        }, intervalMs);
    }

    /** Clear the failure flags and try again. */
    function retry() {
        failed.value = false;
        slow.value = false;
        if (active()) start();
    }

    onMounted(() => {
        // Inertia raises these for non-Inertia responses (404/419/5xx) and network errors.
        // Cancelling them keeps its generic error modal from popping up every 3 seconds.
        removers = [
            router.on('invalid', (event) => {
                event.preventDefault();
                failed.value = true;
                stop();
            }),
            router.on('exception', (event) => {
                event.preventDefault();
                failed.value = true;
                stop();
            }),
        ];
        if (active()) start();
    });

    onBeforeUnmount(() => {
        stop();
        removers.forEach((remove) => remove());
    });

    return { failed, slow, retry, start };
}
```

Check the installed `@inertiajs/core` (2.0.3) typings for `router.on('invalid' | 'exception', ...)`; if the callback event type has no `preventDefault`, cast it to `CustomEvent`.

- [ ] **Step 2: The preview page (overwrite the placeholder)**

`resources/js/pages/stylizations/Show.vue`:

```vue
<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { usePolling } from '@/composables/usePolling';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type Stylization = {
    id: number;
    status: 'queued' | 'processing' | 'ready' | 'approved' | 'failed' | 'discarded';
    error: string | null;
    cost_credits: number;
    created_at: string;
    creation_id: number | null;
    style: { id: number; name: string; subject: string; look: string; credit_cost: number };
    urls: { original: string | null; result: string | null };
};

const props = defineProps<{ stylization: Stylization; balance: number; restyle_cost: number }>();

const approveForm = useForm({});
const retryForm = useForm({});

const working = computed(() => props.stylization.status === 'queued' || props.stylization.status === 'processing');
const ready = computed(() => props.stylization.status === 'ready');
const failed = computed(() => props.stylization.status === 'failed');
const buildCost = computed(() => props.stylization.style.credit_cost);
const canBuild = computed(() => props.balance >= buildCost.value);
const canRetry = computed(() => props.balance >= props.restyle_cost);
const credits = (n: number) => `${n} credit${n === 1 ? '' : 's'}`;

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Create', href: '/create' },
    { title: `${props.stylization.style.name} ${props.stylization.style.subject} preview`, href: `/stylizations/${props.stylization.id}` },
]);

const polling = usePolling({ only: ['stylization'], active: () => working.value });

function approve() {
    approveForm.post(`/stylizations/${props.stylization.id}/approve`, { preserveScroll: true });
}

function retry() {
    retryForm.post(`/stylizations/${props.stylization.id}/retry`);
}

function discard() {
    if (confirm('Throw this preview away?')) router.delete(`/stylizations/${props.stylization.id}`);
}
</script>

<template>
    <Head :title="`${stylization.style.name} ${stylization.style.subject} preview`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
            <header>
                <h1 class="text-2xl font-semibold tracking-tight capitalize">{{ stylization.style.name }} {{ stylization.style.subject }} preview</h1>
                <p class="mt-1 text-sm text-muted-foreground">Check the look before we build the 3D model.</p>
            </header>

            <!-- making the preview -->
            <section v-if="working" class="grid gap-4 sm:grid-cols-2" aria-live="polite">
                <img v-if="stylization.urls.original" :src="stylization.urls.original" alt="Your original photo" class="aspect-[2/3] w-full rounded-xl border object-cover" />
                <div class="flex flex-col justify-center gap-3">
                    <p class="font-medium">Making your preview…</p>
                    <div class="h-2 overflow-hidden rounded-full bg-muted" role="progressbar" aria-label="Preview progress">
                        <div class="h-full w-1/3 animate-pulse rounded-full bg-primary" />
                    </div>
                    <p class="text-sm text-muted-foreground">Usually under a minute. You can leave this page; the preview will wait for you under My Creations.</p>
                    <p v-if="polling.slow.value" class="text-sm text-amber-700 dark:text-amber-300" role="status">
                        This is taking longer than expected. If it doesn't finish soon your credit is refunded automatically.
                    </p>
                    <p v-if="polling.failed.value" class="text-sm text-destructive" role="alert">
                        We couldn't check on the preview just now.
                        <button type="button" class="underline" @click="polling.retry()">Check again</button>
                    </p>
                </div>
            </section>

            <!-- ready: compare and decide -->
            <section v-else-if="ready" class="space-y-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <figure class="space-y-2">
                        <img v-if="stylization.urls.original" :src="stylization.urls.original" alt="Your original photo" class="aspect-[2/3] w-full rounded-xl border object-cover" />
                        <figcaption class="text-center text-sm text-muted-foreground">Your photo</figcaption>
                    </figure>
                    <figure class="space-y-2">
                        <img v-if="stylization.urls.result" :src="stylization.urls.result" alt="Restyled preview of your figure" class="aspect-[2/3] w-full rounded-xl border object-cover" />
                        <figcaption class="text-center text-sm text-muted-foreground">Your figure preview</figcaption>
                    </figure>
                </div>

                <div class="flex flex-col gap-3 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm">
                        Balance: <strong>{{ balance }}</strong> credits.
                        <span class="text-muted-foreground">Building the 3D model costs {{ credits(buildCost) }}. It's refunded if the build fails.</span>
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button v-if="canBuild" :disabled="approveForm.processing" @click="approve">
                            {{ approveForm.processing ? 'Starting…' : `Build 3D model (${credits(buildCost)})` }}
                        </Button>
                        <Button v-else as-child><Link href="/credits">Add credits to build</Link></Button>
                        <Button variant="outline" :disabled="!canRetry || retryForm.processing" @click="retry">
                            Try again ({{ credits(restyle_cost) }})
                        </Button>
                    </div>
                </div>

                <p v-if="approveForm.errors.approve" class="text-sm text-destructive" role="alert">{{ approveForm.errors.approve }}</p>
                <p v-if="retryForm.errors.retry" class="text-sm text-destructive" role="alert">{{ retryForm.errors.retry }}</p>

                <div class="flex gap-4 text-sm">
                    <Link href="/create" class="underline">Start over with another photo</Link>
                    <button type="button" class="text-muted-foreground underline" @click="discard">Throw this preview away</button>
                </div>
            </section>

            <!-- failed -->
            <section v-else-if="failed" class="space-y-4 rounded-xl border border-destructive/40 p-5" role="alert">
                <p class="font-medium text-destructive">We couldn't make a preview.</p>
                <p v-if="stylization.error" class="text-sm text-muted-foreground">{{ stylization.error }}</p>
                <p class="text-sm">Your {{ credits(stylization.cost_credits) }} {{ stylization.cost_credits === 1 ? 'has' : 'have' }} been refunded.</p>
                <div class="flex gap-3">
                    <Button as-child><Link href="/create">Try another photo</Link></Button>
                    <Button variant="outline" :disabled="!canRetry || retryForm.processing" @click="retry">Try again ({{ credits(restyle_cost) }})</Button>
                </div>
                <p v-if="retryForm.errors.retry" class="text-sm text-destructive">{{ retryForm.errors.retry }}</p>
            </section>

            <!-- discarded / anything else -->
            <section v-else class="rounded-xl border p-5 text-sm text-muted-foreground">
                This preview is no longer available. <Link href="/create" class="underline">Start a new one</Link>.
            </section>
        </div>
    </AppLayout>
</template>
```

- [ ] **Step 3: Update `/create`**

Apply these edits to `resources/js/pages/Create.vue`:

1. Props line: `const props = defineProps<{ styles: StyleOption[]; balance: number }>();` becomes
`const props = defineProps<{ styles: StyleOption[]; balance: number; restyle_cost: number }>();`

2. Replace the three computed lines `const cost = ...`, `const canAfford = ...`, `const canSubmit = ...` with:

```ts
const cost = computed(() => selected.value?.credit_cost ?? 0);
const canAfford = computed(() => props.balance >= props.restyle_cost);
const lowForBuild = computed(() => !!selected.value && props.balance < props.restyle_cost + cost.value);
const credits = (n: number) => `${n} credit${n === 1 ? '' : 's'}`;
const canSubmit = computed(() => !!form.photo && !!selected.value && canAfford.value && !form.processing);
```

3. In `submit()` change `form.post('/creations', { forceFormData: true });` to `form.post('/stylizations', { forceFormData: true });`.

4. Header copy: `Turn a photo into a 3D model` becomes `Turn a photo into a 3D figure`, and the paragraph becomes `Upload a photo and choose a style. We'll show you a preview first, then build the 3D model once you approve it.`

5. Under the existing tip paragraph (`Tip: a front-facing photo...`) add:

```vue
                <p class="text-xs text-muted-foreground">Your photo is sent to our AI partners (OpenAI and Meshy) to make your figure, and deleted from our servers once you approve the preview.</p>
```

6. Replace the whole `<!-- 3. Review -->` section with:

```vue
            <!-- 3. Review -->
            <section class="flex flex-col gap-3 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="text-sm">
                    <div>Balance: <strong>{{ balance }}</strong> credits</div>
                    <div v-if="selected" class="text-muted-foreground">
                        The preview costs {{ credits(restyle_cost) }}. Building the 3D model afterwards costs {{ credits(cost) }}. Credits are refunded if a step fails.
                    </div>
                    <div v-if="lowForBuild && canAfford" class="mt-1 text-amber-700 dark:text-amber-300" role="status">
                        You'll need {{ restyle_cost + cost }} credits in total to build the model. <Link href="/credits" class="underline">Add credits</Link>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <Link v-if="!canAfford" href="/credits" class="text-sm underline">Add credits</Link>
                    <Button :disabled="!canSubmit" @click="submit">
                        {{ form.processing ? 'Uploading…' : `Preview (${credits(restyle_cost)})` }}
                    </Button>
                </div>
            </section>
```

7. In the style cards, the `{{ style.credit_cost }} credits` line stays (it is the 3D price); change its text to `{{ style.credit_cost }} credits to build`.

- [ ] **Step 4: Verify (including at runtime)**

```bash
npx vue-tsc --noEmit
npm run build
npx eslint resources/js/composables/usePolling.ts resources/js/pages/stylizations/Show.vue resources/js/pages/Create.vue
```
Expected: only the 2 known TS2688 errors; build passes; eslint clean.

Runtime check in a browser (built-in browser tools or Playwright; if neither is available report BLOCKED): `npm run build`, run `php artisan serve` on a free port and `php artisan queue:listen --tries=1 --timeout=0`, register a throwaway user (20 free credits), then:
1. `/create` shows the new copy, the privacy note, "Preview (1 credit)"; selecting a style shows both costs; no console errors.
2. Upload an image of at least 512 px and submit: you land on `/stylizations/{id}`, the "Making your preview…" state shows, then flips to the side-by-side comparison within a few seconds (polling) with the mock border visible on the restyled image.
3. **Try again** creates a new preview (balance drops by 1) and the old one is gone.
4. **Build 3D model** redirects to the creation page (the existing page) and balance drops by the style price.
5. Set `STYLIZER_MOCK_FAIL_RATE=1` in `.env`, restart the queue listener, upload again: the failed state shows with the refund note and the balance is restored; restore `STYLIZER_MOCK_FAIL_RATE=0`.
6. Stop the dev server while the preview page is polling, wait 10 seconds: the page shows the "couldn't check" message with **Check again** and does not spam an error modal; restart the server and click Check again.
Clean up: delete the throwaway user and its files, stop all servers, restore `.env`. Never run `migrate:fresh`.

- [ ] **Step 5: Commit**

```bash
git add resources
git commit -m "feat: add preview page, polling composable and the new create flow

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Frontend: creation page, previews strip, credits and landing

**Files:**
- Modify: `resources/js/pages/creations/Show.vue`, `resources/js/pages/creations/Index.vue`, `resources/js/pages/Credits.vue`, `resources/js/pages/Welcome.vue`

**Interfaces:**
- Consumes: `usePolling` (Task 9), `POST /creations/{id}/retry` (Task 8), `previews` prop (Task 8), ledger reasons `stylize` and `stylize_refund` (Task 1; the existing credits page already sends `reason` as the raw enum value).
- Produces: nothing new for later tasks.

Verification as in Task 9 (type check with only the 2 known errors, build, eslint on the changed files, **runtime browser check**).

- [ ] **Step 1: Creation page (replace the whole file)**

`resources/js/pages/creations/Show.vue`:

```vue
<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import ModelViewer from '@/components/ModelViewer.vue';
import { Button } from '@/components/ui/button';
import { usePolling } from '@/composables/usePolling';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type Creation = {
    id: number;
    status: 'queued' | 'processing' | 'succeeded' | 'failed';
    error: string | null;
    progress: number | null;
    cost_credits: number;
    created_at: string;
    style: { name: string; subject: string; look: string };
    urls: { source: string; model: string | null; thumbnail: string | null; download: string | null };
};

const props = defineProps<{ creation: Creation }>();

const retryForm = useForm({});

const working = computed(() => props.creation.status === 'queued' || props.creation.status === 'processing');
const failed = computed(() => props.creation.status === 'failed');
const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'My Creations', href: '/creations' },
    { title: `${props.creation.style.name} ${props.creation.style.subject}`, href: `/creations/${props.creation.id}` },
]);

const polling = usePolling({ only: ['creation'], active: () => working.value });

function retry() {
    retryForm.post(`/creations/${props.creation.id}/retry`);
}

function remove() {
    if (confirm('Delete this creation? This cannot be undone.')) router.delete(`/creations/${props.creation.id}`);
}
</script>

<template>
    <Head :title="`${creation.style.name} ${creation.style.subject}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
            <header>
                <h1 class="text-2xl font-semibold tracking-tight capitalize">{{ creation.style.name }} {{ creation.style.subject }}</h1>
                <p class="mt-1 text-sm text-muted-foreground capitalize">{{ creation.status }}</p>
            </header>

            <!-- queued / processing -->
            <section v-if="working" class="grid gap-4 sm:grid-cols-2" aria-live="polite">
                <img :src="creation.urls.source" alt="The approved preview being turned into 3D" class="aspect-[2/3] w-full rounded-xl border object-cover" />
                <div class="flex flex-col justify-center gap-3">
                    <p class="font-medium">Building your 3D model…</p>
                    <div
                        class="h-2 overflow-hidden rounded-full bg-muted"
                        role="progressbar"
                        aria-label="3D model progress"
                        :aria-valuenow="creation.progress ?? undefined"
                        aria-valuemin="0"
                        aria-valuemax="100"
                    >
                        <div class="h-full bg-primary transition-all" :style="{ width: `${creation.progress ?? 5}%` }" />
                    </div>
                    <p class="text-sm text-muted-foreground">This can take a few minutes. You can leave this page — it will keep going and appear in My Creations.</p>
                    <p v-if="polling.slow.value" class="text-sm text-amber-700 dark:text-amber-300" role="status">
                        This is taking longer than expected. If it doesn't finish soon your credits are refunded automatically.
                    </p>
                    <p v-if="polling.failed.value" class="text-sm text-destructive" role="alert">
                        We couldn't check on your model just now.
                        <button type="button" class="underline" @click="polling.retry()">Check again</button>
                    </p>
                </div>
            </section>

            <!-- succeeded -->
            <section v-else-if="creation.status === 'succeeded' && creation.urls.model" class="space-y-4">
                <ModelViewer :src="creation.urls.model" />
                <div class="flex flex-wrap gap-3">
                    <Button v-if="creation.urls.download" as-child><a :href="creation.urls.download">Download GLB</a></Button>
                    <Button variant="outline" @click="remove">Delete</Button>
                </div>
            </section>

            <!-- failed: the only state that mentions a refund -->
            <section v-else-if="failed" class="space-y-4 rounded-xl border border-destructive/40 p-5" role="alert">
                <p class="font-medium text-destructive">We couldn't build this model.</p>
                <p v-if="creation.error" class="text-sm text-muted-foreground">{{ creation.error }}</p>
                <p class="text-sm">Your {{ creation.cost_credits }} credits have been refunded. You can try the same image again, or start over.</p>
                <p v-if="retryForm.errors.retry" class="text-sm text-destructive">{{ retryForm.errors.retry }}</p>
                <div class="flex flex-wrap gap-3">
                    <Button :disabled="retryForm.processing" @click="retry">Try again ({{ creation.cost_credits }} credits)</Button>
                    <Button variant="outline" as-child><Link href="/create">Start over</Link></Button>
                    <Button variant="outline" @click="remove">Delete</Button>
                </div>
            </section>

            <!-- anything else, e.g. succeeded without a model file -->
            <section v-else class="space-y-4 rounded-xl border p-5">
                <p class="font-medium">The model file isn't available.</p>
                <p class="text-sm text-muted-foreground">Please contact support or delete this creation and try again.</p>
                <Button variant="outline" @click="remove">Delete</Button>
            </section>
        </div>
    </AppLayout>
</template>
```

- [ ] **Step 2: Previews strip on My Creations**

In `resources/js/pages/creations/Index.vue`:

1. Add a type and prop below the `Creation` type, and change the `defineProps` line:

```ts
type Preview = {
    id: number;
    style: { name: string; subject: string };
    urls: { result: string | null };
};

defineProps<{ creations: Creation[]; previews: Preview[] }>();
```

2. Insert this block directly after the closing `</header>` tag and before the `<div v-if="creations.length === 0" ...>` empty state:

```vue
            <section v-if="previews.length" class="space-y-3" aria-labelledby="previews-heading">
                <h2 id="previews-heading" class="text-sm font-medium">Previews waiting for your approval</h2>
                <div class="flex gap-3 overflow-x-auto pb-1">
                    <Link
                        v-for="p in previews"
                        :key="p.id"
                        :href="`/stylizations/${p.id}`"
                        class="w-32 shrink-0 overflow-hidden rounded-xl border transition-shadow hover:shadow-md"
                    >
                        <img :src="p.urls.result ?? ''" :alt="`${p.style.name} ${p.style.subject} preview`" class="aspect-[2/3] w-full object-cover" loading="lazy" />
                        <span class="block truncate p-2 text-xs capitalize">{{ p.style.name }} {{ p.style.subject }} · Continue</span>
                    </Link>
                </div>
            </section>
```

3. Change the empty-state condition so it only shows when there are neither creations nor previews: `v-if="creations.length === 0"` becomes `v-if="creations.length === 0 && previews.length === 0"`, and change the grid's `v-else` to `v-else-if="creations.length > 0"`.

- [ ] **Step 3: Credits labels**

In `resources/js/pages/Credits.vue` change the `Entry` type's `reason` union and the `label` map:

```ts
type Entry = {
    id: number;
    delta: number;
    reason: 'signup' | 'topup' | 'generation' | 'refund' | 'stylize' | 'stylize_refund';
    created_at: string;
};
```

and add to the `label` record:

```ts
    stylize: 'Preview',
    stylize_refund: 'Preview refund',
```

- [ ] **Step 4: Landing page "How it works"**

In `resources/js/pages/Welcome.vue` replace the `steps` array with:

```ts
const steps = [
    { title: 'Upload', body: 'Add a clear photo of a person, pet, or favourite object.' },
    { title: 'Pick a style', body: 'Chibi, clay, sleepy, realistic or cartoon: choose the look you like.' },
    { title: 'Preview and approve', body: 'See your figure as a picture first. Happy with it? Approve it, or try again.' },
    { title: 'Get your 3D model', body: 'Spin it around in your browser and download it, ready for printing.' },
];
```

and change the list grid class `md:grid-cols-3` to `md:grid-cols-2 lg:grid-cols-4`.

- [ ] **Step 5: Verify (including at runtime) and commit**

```bash
npx vue-tsc --noEmit
npm run build
npx eslint resources/js/pages/creations/Show.vue resources/js/pages/creations/Index.vue resources/js/pages/Credits.vue resources/js/pages/Welcome.vue
```
Runtime check as in Task 9 (throwaway user, mock providers, real queue worker): the landing page shows four steps and the demo viewer still renders; after a preview is made, My Creations shows the "Previews waiting for your approval" strip with a working Continue link; approving goes to the creation page, which shows the building state then the viewer and GLB download; set `MODEL_MOCK_FAIL_RATE=1`, restart the worker, approve another preview: the failed state shows the refund text and **Try again** creates a new creation (restore `MODEL_MOCK_FAIL_RATE=0` afterwards); the credits page shows "Preview", "Preview refund", "Model generation" and "Refund" rows; stop the server while a creation is building and confirm the "couldn't check" message appears with no error-modal spam; zero console errors on every page. Clean up as in Task 9.

```bash
git add resources
git commit -m "feat: update creation page, previews strip, credits labels and landing

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```


---

### Task 11: OpenAiStylizer

**Files:**
- Create: `app/Services/Stylizers/OpenAiStylizer.php`
- Modify: `app/Providers/CreatorServiceProvider.php`
- Test: `tests/Feature/OpenAiStylizerTest.php`

**Interfaces:**
- Consumes: `ImageStylizer`, `TransientProviderException`, `PermanentProviderException`, `config('services.openai.key')`, `config('stylizer.openai.*')` (Task 3).
- Produces: `OpenAiStylizer implements ImageStylizer`; container binding for `STYLIZER_PROVIDER=openai`. Customer-facing messages are the public constants `OpenAiStylizer::REFUSED`, `::FAILED`, `::UNAVAILABLE`.

Real calls are never made in tests: everything is verified with `Http::fake()`.

- [ ] **Step 1: Re-check the API against the current docs**

Before writing code, look up the current OpenAI image-edit documentation (use the Context7 tools for the OpenAI API, library `/websites/developers_openai_api`, query "images edits endpoint parameters gpt-image model input_fidelity quality size output_format response b64_json error codes moderation_blocked"). Confirm: the endpoint path (`POST /v1/images/edits`, multipart), the field names below, that the response carries `data[0].b64_json`, that `input_fidelity=high` and the `quality` and `size` values in `config/stylizer.php` are accepted for the default model, and the error codes for a content-policy block and for quota/billing problems. If anything differs from this task's code, adjust the code and tests to the docs and list the differences in your report.

- [ ] **Step 2: Write the failing tests**

`tests/Feature/OpenAiStylizerTest.php`:

```php
<?php

use App\Models\Style;
use App\Services\ModelProviders\PermanentProviderException;
use App\Services\ModelProviders\TransientProviderException;
use App\Services\Stylizers\ImageStylizer;
use App\Services\Stylizers\OpenAiStylizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config([
        'services.openai.key' => 'test-key',
        'stylizer.openai.model' => 'gpt-image-1.5',
        'stylizer.openai.quality' => 'medium',
        'stylizer.openai.size' => '1024x1536',
    ]);
    $this->photo = tempnam(sys_get_temp_dir(), 'photo');
    file_put_contents($this->photo, fakeJpegBytes(300, 400));
    $this->style = Style::factory()->make(['prompt' => 'Make it a tiny vinyl figure.']);
    $this->png = fakeJpegBytes(64, 96); // any decodable image bytes will do for the response
    $this->ok = fn () => Http::response(['data' => [['b64_json' => base64_encode($this->png)]]], 200);
});

it('sends the photo and the style prompt to the image edit endpoint', function () {
    Http::fake(['api.openai.com/*' => ($this->ok)()]);

    $result = (new OpenAiStylizer)->stylize($this->photo, $this->style);

    expect($result)->toBe($this->png);
    Http::assertSent(function (Request $request) {
        $data = $request->data();

        return $request->url() === 'https://api.openai.com/v1/images/edits'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $data['model'] === 'gpt-image-1.5'
            && $data['prompt'] === 'Make it a tiny vinyl figure.'
            && $data['size'] === '1024x1536'
            && $data['quality'] === 'medium'
            && $data['input_fidelity'] === 'high'
            && $data['output_format'] === 'png'
            && isset($data['image']);
    });
});

it('is bound when the provider is openai', function () {
    config(['stylizer.provider' => 'openai']);

    expect(app(ImageStylizer::class))->toBeInstanceOf(OpenAiStylizer::class);
});

it('maps a content-policy refusal to a customer-safe permanent error', function () {
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['code' => 'moderation_blocked', 'message' => 'Your request was rejected by the safety system.']], 400)]);

    expect(fn () => (new OpenAiStylizer)->stylize($this->photo, $this->style))
        ->toThrow(PermanentProviderException::class, OpenAiStylizer::REFUSED);
});

it('maps other bad requests to a generic permanent error', function () {
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['code' => 'invalid_value', 'message' => 'bad size']], 400)]);

    expect(fn () => (new OpenAiStylizer)->stylize($this->photo, $this->style))
        ->toThrow(PermanentProviderException::class, OpenAiStylizer::FAILED);
});

it('refunds the customer but alerts the operator on account problems', function (int $status, array $body) {
    Log::spy();
    Http::fake(['api.openai.com/*' => Http::response($body, $status)]);

    expect(fn () => (new OpenAiStylizer)->stylize($this->photo, $this->style))
        ->toThrow(PermanentProviderException::class, OpenAiStylizer::UNAVAILABLE);

    Log::shouldHaveReceived('critical')->once();
})->with([
    'bad key' => [401, ['error' => ['code' => 'invalid_api_key']]],
    'not verified or no access' => [403, ['error' => ['code' => 'model_not_found']]],
    'out of quota' => [429, ['error' => ['code' => 'insufficient_quota']]],
]);

it('retries later on rate limits and server errors', function (int $status) {
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['code' => 'rate_limit_exceeded']], $status)]);

    expect(fn () => (new OpenAiStylizer)->stylize($this->photo, $this->style))
        ->toThrow(TransientProviderException::class);
})->with([429, 500, 502, 503]);

it('retries later when the connection fails or times out', function () {
    Http::fake(['api.openai.com/*' => fn () => throw new ConnectionException('timeout')]);

    expect(fn () => (new OpenAiStylizer)->stylize($this->photo, $this->style))
        ->toThrow(TransientProviderException::class);
});

it('fails permanently when the response has no usable image', function () {
    Http::fake(['api.openai.com/*' => Http::response(['data' => [['b64_json' => base64_encode('not an image')]]], 200)]);

    expect(fn () => (new OpenAiStylizer)->stylize($this->photo, $this->style))
        ->toThrow(PermanentProviderException::class, OpenAiStylizer::FAILED);
});

it('is unavailable without an api key and never calls out', function () {
    Log::spy();
    config(['services.openai.key' => null]);
    Http::fake();

    expect(fn () => (new OpenAiStylizer)->stylize($this->photo, $this->style))
        ->toThrow(PermanentProviderException::class, OpenAiStylizer::UNAVAILABLE);

    Http::assertNothingSent();
    Log::shouldHaveReceived('critical')->once();
});

it('refuses a style that has no prompt', function () {
    Http::fake();
    $style = Style::factory()->make(['prompt' => null]);

    expect(fn () => (new OpenAiStylizer)->stylize($this->photo, $style))
        ->toThrow(PermanentProviderException::class, OpenAiStylizer::FAILED);

    Http::assertNothingSent();
});

it('never logs the key, the prompt or image bytes', function () {
    Log::spy();
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['code' => 'invalid_api_key']], 401)]);

    try {
        (new OpenAiStylizer)->stylize($this->photo, $this->style);
    } catch (PermanentProviderException) {
    }

    Log::shouldHaveReceived('critical')->withArgs(function (string $message, array $context = []) {
        $dump = $message.json_encode($context);

        return ! str_contains($dump, 'test-key') && ! str_contains($dump, 'tiny vinyl figure');
    });
});
```

- [ ] **Step 3: Run to verify it fails**

Run: `php artisan test tests/Feature/OpenAiStylizerTest.php`
Expected: FAIL (class missing).

- [ ] **Step 4: Implement**

`app/Services/Stylizers/OpenAiStylizer.php`:

```php
<?php

namespace App\Services\Stylizers;

use App\Models\Style;
use App\Services\ModelProviders\PermanentProviderException;
use App\Services\ModelProviders\TransientProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class OpenAiStylizer implements ImageStylizer
{
    private const URL = 'https://api.openai.com/v1/images/edits';

    /** Customer-facing messages: plain, and never reveal our account or the provider's wording. */
    public const REFUSED = "We can't process this photo. Please try a different one.";

    public const FAILED = "We couldn't process this photo.";

    public const UNAVAILABLE = 'Previews are temporarily unavailable. Please try again later.';

    public function stylize(string $photoPath, Style $style): string
    {
        $key = config('services.openai.key');

        if (! $key) {
            Log::critical('OpenAI API key is not configured; previews are unavailable.');

            throw new PermanentProviderException(self::UNAVAILABLE);
        }

        if (! is_string($style->prompt) || $style->prompt === '') {
            Log::error('A style without a prompt was sent for restyling.', ['style_id' => $style->id]);

            throw new PermanentProviderException(self::FAILED);
        }

        $contents = is_file($photoPath) ? file_get_contents($photoPath) : false;

        if ($contents === false) {
            throw new PermanentProviderException('We could not read that photo.');
        }

        $settings = config('stylizer.openai');

        try {
            $response = Http::withToken($key)
                ->connectTimeout(10)
                ->timeout((int) $settings['timeout_seconds'])
                ->attach('image', $contents, 'photo.jpg', ['Content-Type' => 'image/jpeg'])
                ->post(self::URL, [
                    'model' => $settings['model'],
                    'prompt' => $style->prompt,
                    'size' => $settings['size'],
                    'quality' => $settings['quality'],
                    'input_fidelity' => $settings['input_fidelity'],
                    'output_format' => 'png',
                ]);
        } catch (ConnectionException $e) {
            throw new TransientProviderException('The image service could not be reached.', 0, $e);
        }

        return $this->imageFrom($response);
    }

    private function imageFrom(Response $response): string
    {
        if ($response->successful()) {
            $encoded = $response->json('data.0.b64_json');
            $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;

            if ($bytes === false || @getimagesizefromstring($bytes) === false) {
                Log::warning('OpenAI returned no usable image.', ['status' => $response->status()]);

                throw new PermanentProviderException(self::FAILED);
            }

            return $bytes;
        }

        $status = $response->status();
        $code = (string) $response->json('error.code');
        $message = strtolower((string) $response->json('error.message'));

        // Our account is the problem (bad key, no access, out of quota): refund the customer,
        // tell the operator. Only the status and error code are logged, never bodies or keys.
        $outOfQuota = $status === 429 && str_contains($code, 'quota');

        if ($status === 401 || $status === 403 || $outOfQuota || $code === 'billing_hard_limit_reached') {
            Log::critical('OpenAI account problem: previews are failing.', ['status' => $status, 'code' => $code]);

            throw new PermanentProviderException(self::UNAVAILABLE);
        }

        if ($status === 429 || $status === 408 || $status >= 500) {
            throw new TransientProviderException("The image service is busy (HTTP {$status}).");
        }

        if ($code === 'moderation_blocked' || str_contains($message, 'safety system')) {
            throw new PermanentProviderException(self::REFUSED);
        }

        Log::warning('OpenAI rejected an image edit request.', ['status' => $status, 'code' => $code]);

        throw new PermanentProviderException(self::FAILED);
    }
}
```

In `app/Providers/CreatorServiceProvider.php` add `use App\Services\Stylizers\OpenAiStylizer;` and add the arm to the `ImageStylizer` match: `'openai' => app(OpenAiStylizer::class),` (before `default`).

- [ ] **Step 5: Run tests and commit**

```bash
php artisan test tests/Feature/OpenAiStylizerTest.php
php artisan test
./vendor/bin/pint app tests/Feature/OpenAiStylizerTest.php
git add app tests
git commit -m "feat: add OpenAiStylizer for the image-edit API

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```
Expected: PASS. If `$request->data()` does not expose multipart fields in the installed Laravel, assert on `$request->body()` containing each field value instead and say so in the report.

---

### Task 12: MeshyProvider and STL handling

**Files:**
- Create: `app/Services/ModelProviders/MeshyProvider.php`
- Modify: `app/Services/ModelProviders/{ProviderResult,MockProvider,MockAssets}.php`, `app/Jobs/GenerateCreation.php`, `app/Providers/CreatorServiceProvider.php`, `config/models.php`
- Test: `tests/Feature/MeshyProviderTest.php`; additions to `tests/Feature/MockProviderTest.php` and `tests/Feature/GenerateCreationTest.php`

**Interfaces:**
- Consumes: `ModelProvider`, `ProviderResult`, `ProviderState`, exceptions, `config('services.meshy.*')`.
- Produces: `ProviderResult::$printModelUrl` (new optional last constructor argument); `MeshyProvider implements ModelProvider`; `MockAssets::stl(Subject): string` (ASCII STL cube); `MockProvider` returns `mock://print/{subject}`; `GenerateCreation` stores the STL at `creations/{id}/print.stl` and records `print_model_path`; binding for `MODEL_PROVIDER=meshy`; config `models.meshy.{timeout_seconds,default_polycount}`.

- [ ] **Step 1: Re-check the API against the current docs**

Look up the current Meshy documentation (Context7 library `/websites/meshy_ai_en`; queries: "Image to 3D create task response body id result", "task status values PENDING IN_PROGRESS SUCCEEDED FAILED CANCELED", "image_url base64 data URI size limit", "target_formats stl glb", "target_polycount range", "error codes 400 401 402 403 404 429"). Confirm the create-response field that holds the task id (this plan assumes `result`), the exact status words, `model_urls.glb` and `model_urls.stl`, `thumbnail_url`, `task_error.message`, the request fields used below, the base64 size limit, and whether `ai_model` is a valid optional field. If anything differs, adjust the code and tests to the docs and list the differences in your report. If STL output requires a specific plan or flag, note that for the owner in the report.

- [ ] **Step 2: Write the failing tests**

`tests/Feature/MeshyProviderTest.php`:

```php
<?php

use App\Enums\Subject;
use App\Models\Style;
use App\Services\ModelProviders\MeshyProvider;
use App\Services\ModelProviders\ModelProvider;
use App\Services\ModelProviders\PermanentProviderException;
use App\Services\ModelProviders\ProviderState;
use App\Services\ModelProviders\TransientProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config(['services.meshy.key' => 'meshy-key', 'services.meshy.model' => null, 'models.meshy.default_polycount' => 30000]);
    $this->image = tempnam(sys_get_temp_dir(), 'img');
    file_put_contents($this->image, fakeJpegBytes(256, 384));
    $this->style = Style::factory()->make(['subject' => Subject::Person, 'provider_params' => ['target_faces' => 20000]]);
    $this->provider = new MeshyProvider;
});

it('is bound when the provider is meshy', function () {
    config(['models.provider' => 'meshy']);

    expect(app(ModelProvider::class))->toBeInstanceOf(MeshyProvider::class);
});

describe('start', function () {
    it('creates an image-to-3d task asking for a textured glb and a printable stl', function () {
        Http::fake(['api.meshy.ai/*' => Http::response(['result' => 'task_123'], 202)]);

        expect($this->provider->start($this->image, $this->style))->toBe('task_123');

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === 'https://api.meshy.ai/openapi/v1/image-to-3d'
                && $request->hasHeader('Authorization', 'Bearer meshy-key')
                && str_starts_with($body['image_url'], 'data:image/jpeg;base64,')
                && $body['should_texture'] === true
                && $body['enable_pbr'] === false
                && $body['should_remesh'] === true
                && $body['target_polycount'] === 20000
                && $body['target_formats'] === ['glb', 'stl']
                && ! array_key_exists('ai_model', $body);
        });
    });

    it('falls back to the default polycount and passes a configured model', function () {
        config(['services.meshy.model' => 'meshy-6']);
        Http::fake(['api.meshy.ai/*' => Http::response(['result' => 'task_1'], 202)]);

        $this->provider->start($this->image, Style::factory()->make(['provider_params' => []]));

        Http::assertSent(fn (Request $r) => $r->data()['target_polycount'] === 30000 && $r->data()['ai_model'] === 'meshy-6');
    });

    it('maps request problems', function (int $status, string $exception) {
        Http::fake(['api.meshy.ai/*' => Http::response(['message' => 'x'], $status)]);

        expect(fn () => $this->provider->start($this->image, $this->style))->toThrow($exception);
    })->with([
        'bad image' => [400, PermanentProviderException::class],
        'rate limit' => [429, TransientProviderException::class],
        'server error' => [500, TransientProviderException::class],
        'unavailable' => [503, TransientProviderException::class],
    ]);

    it('refunds the customer but alerts the operator on account problems', function (int $status) {
        Log::spy();
        Http::fake(['api.meshy.ai/*' => Http::response(['message' => 'x'], $status)]);

        expect(fn () => $this->provider->start($this->image, $this->style))->toThrow(PermanentProviderException::class);

        Log::shouldHaveReceived('critical')->once();
    })->with([401, 402, 403]);

    it('retries later when the connection fails', function () {
        Http::fake(['api.meshy.ai/*' => fn () => throw new ConnectionException('timeout')]);

        expect(fn () => $this->provider->start($this->image, $this->style))->toThrow(TransientProviderException::class);
    });

    it('fails permanently when no task id comes back', function () {
        Http::fake(['api.meshy.ai/*' => Http::response(['unexpected' => true], 200)]);

        expect(fn () => $this->provider->start($this->image, $this->style))->toThrow(PermanentProviderException::class);
    });

    it('is unavailable without an api key and never calls out', function () {
        Log::spy();
        config(['services.meshy.key' => null]);
        Http::fake();

        expect(fn () => $this->provider->start($this->image, $this->style))->toThrow(PermanentProviderException::class);

        Http::assertNothingSent();
        Log::shouldHaveReceived('critical')->once();
    });

    it('never logs the key or image bytes', function () {
        Log::spy();
        Http::fake(['api.meshy.ai/*' => Http::response(['message' => 'x'], 401)]);

        try {
            $this->provider->start($this->image, $this->style);
        } catch (PermanentProviderException) {
        }

        Log::shouldHaveReceived('critical')->withArgs(function (string $message, array $context = []) {
            return ! str_contains($message.json_encode($context), 'meshy-key');
        });
    });
});

describe('status', function () {
    it('maps task states', function (string $meshyStatus, ProviderState $state) {
        Http::fake(['api.meshy.ai/*' => Http::response(['status' => $meshyStatus, 'progress' => 40], 200)]);

        expect($this->provider->status('task_123')->state)->toBe($state);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.meshy.ai/openapi/v1/image-to-3d/task_123' && $r->method() === 'GET');
    })->with([
        ['PENDING', ProviderState::Pending],
        ['IN_PROGRESS', ProviderState::Running],
        ['FAILED', ProviderState::Failed],
        ['CANCELED', ProviderState::Failed],
    ]);

    it('reports progress while running', function () {
        Http::fake(['api.meshy.ai/*' => Http::response(['status' => 'IN_PROGRESS', 'progress' => 40], 200)]);

        expect($this->provider->status('t')->progress)->toBe(40);
    });

    it('returns the glb, stl and thumbnail urls when finished', function () {
        Http::fake(['api.meshy.ai/*' => Http::response([
            'status' => 'SUCCEEDED',
            'progress' => 100,
            'model_urls' => ['glb' => 'https://assets.meshy.ai/a/model.glb?Expires=1', 'stl' => 'https://assets.meshy.ai/a/model.stl?Expires=1'],
            'thumbnail_url' => 'https://assets.meshy.ai/a/preview.png?Expires=1',
        ], 200)]);

        $result = $this->provider->status('t');

        expect($result->state)->toBe(ProviderState::Succeeded)
            ->and($result->modelUrl)->toBe('https://assets.meshy.ai/a/model.glb?Expires=1')
            ->and($result->printModelUrl)->toBe('https://assets.meshy.ai/a/model.stl?Expires=1')
            ->and($result->thumbnailUrl)->toBe('https://assets.meshy.ai/a/preview.png?Expires=1');
    });

    it('shows a plain message, never the provider text, when the build fails', function () {
        Log::spy();
        Http::fake(['api.meshy.ai/*' => Http::response(['status' => 'FAILED', 'task_error' => ['message' => 'internal gpu error 0xDEAD']], 200)]);

        $result = $this->provider->status('t');

        expect($result->state)->toBe(ProviderState::Failed)
            ->and($result->error)->toBe('The 3D model could not be built from this image.')
            ->and($result->error)->not->toContain('0xDEAD');
        Log::shouldHaveReceived('warning')->once();
    });

    it('treats an unknown task as failed', function () {
        Http::fake(['api.meshy.ai/*' => Http::response([], 404)]);

        expect($this->provider->status('gone')->state)->toBe(ProviderState::Failed);
    });

    it('retries later on server errors', function () {
        Http::fake(['api.meshy.ai/*' => Http::response([], 500)]);

        expect(fn () => $this->provider->status('t'))->toThrow(TransientProviderException::class);
    });

    it('retries later when the connection fails', function () {
        Http::fake(['api.meshy.ai/*' => fn () => throw new ConnectionException('timeout')]);

        expect(fn () => $this->provider->status('t'))->toThrow(TransientProviderException::class);
    });
});

describe('download', function () {
    it('fetches files from meshy asset hosts', function () {
        Http::fake(['assets.meshy.ai/*' => Http::response('file-bytes', 200)]);

        expect($this->provider->download('https://assets.meshy.ai/a/model.glb?Expires=1'))->toBe('file-bytes');
    });

    it('refuses any other host or scheme without making a request', function (string $url) {
        Http::fake();

        expect(fn () => $this->provider->download($url))->toThrow(PermanentProviderException::class);

        Http::assertNothingSent();
    })->with([
        'other host' => 'https://evil.example.com/model.glb',
        'lookalike host' => 'https://assets.meshy.ai.evil.com/model.glb',
        'plain http' => 'http://assets.meshy.ai/model.glb',
        'internal address' => 'https://169.254.169.254/latest/meta-data',
        'not a url' => 'mock://model/person',
    ]);

    it('maps download failures by status', function (int $status, string $exception) {
        Http::fake(['assets.meshy.ai/*' => Http::response('', $status)]);

        expect(fn () => $this->provider->download('https://assets.meshy.ai/x.glb'))->toThrow($exception);
    })->with([
        'expired or forbidden link' => [403, PermanentProviderException::class],
        'missing file' => [404, PermanentProviderException::class],
        'service unavailable' => [503, TransientProviderException::class],
    ]);

    it('retries later when the download connection fails', function () {
        Http::fake(['assets.meshy.ai/*' => fn () => throw new ConnectionException('timeout')]);

        expect(fn () => $this->provider->download('https://assets.meshy.ai/x.glb'))->toThrow(TransientProviderException::class);
    });
});
```

Additions to `tests/Feature/MockProviderTest.php` (append; keep every existing test):

```php
it('returns a print model url when finished and serves a valid ascii stl', function () {
    config(['models.mock.delay_seconds' => 0, 'models.mock.fail_rate' => 0]);
    $provider = new MockProvider;
    $id = $provider->start('/tmp/photo.jpg', Style::factory()->make(['subject' => Subject::Pet]));

    $done = $provider->status($id);
    expect($done->printModelUrl)->toBe('mock://print/pet');

    $stl = $provider->download('mock://print/pet');
    expect($stl)->toStartWith('solid mock-pet')
        ->and(substr_count($stl, 'facet normal'))->toBe(12)
        ->and($stl)->toContain('endsolid mock-pet');
});
```

Additions to `tests/Feature/GenerateCreationTest.php` (append inside the file's existing style; reuse its `beforeEach` helpers):

```php
it('stores the print model next to the preview model on success', function () {
    $creation = ($this->makeCreation)();

    ($this->runJob)($creation);

    $creation->refresh();
    expect($creation->print_model_path)->toBe("creations/{$creation->id}/print.stl");
    Storage::disk('local')->assertExists($creation->print_model_path);
    expect(Storage::disk('local')->get($creation->print_model_path))->toStartWith('solid mock-');
});

it('removes all three stored files when a losing run finds the creation already failed', function () {
    $this->app->bind(ModelProvider::class, fn () => new class extends MockProvider
    {
        public function download(string $url): string
        {
            // Another run fails the creation while this one is downloading.
            Creation::query()->update(['status' => CreationStatus::Failed->value]);

            return parent::download($url);
        }
    });
    $creation = ($this->makeCreation)();

    ($this->runJob)($creation);

    expect($creation->fresh()->status)->toBe(CreationStatus::Failed);
    Storage::disk('local')->assertMissing("creations/{$creation->id}/model.glb");
    Storage::disk('local')->assertMissing("creations/{$creation->id}/thumbnail.png");
    Storage::disk('local')->assertMissing("creations/{$creation->id}/print.stl");
});
```

(`MockProvider` is `final`: remove `final` from `MockProvider` so the test can extend it, or build the double by wrapping it; keep the intent either way. Add imports for `MockProvider` and `ModelProvider` to the test file if missing.)

- [ ] **Step 3: Run to verify they fail**

Run: `php artisan test tests/Feature/MeshyProviderTest.php tests/Feature/MockProviderTest.php tests/Feature/GenerateCreationTest.php`
Expected: FAIL.

- [ ] **Step 4: ProviderResult, mock STL, MockProvider**

`app/Services/ModelProviders/ProviderResult.php`: add the last constructor property:

```php
        public readonly ?string $printModelUrl = null,
```
(after `$progress`; all existing call sites use named arguments so nothing else changes).

In `app/Services/ModelProviders/MockAssets.php` add:

```php
    /** A minimal valid ASCII STL: one unit cube made of twelve triangles. */
    public static function stl(Subject $subject): string
    {
        $vertices = [];
        for ($i = 0; $i < 8; $i++) {
            $vertices[] = [($i & 1) ? 0.5 : -0.5, ($i & 2) ? 0.5 : -0.5, ($i & 4) ? 0.5 : -0.5];
        }

        // Same triangle list as the GLB cube: counter-clockwise seen from outside.
        $triangles = [
            [4, 5, 7], [4, 7, 6], [1, 0, 2], [1, 2, 3], [1, 3, 7], [1, 7, 5],
            [0, 4, 6], [0, 6, 2], [2, 6, 7], [2, 7, 3], [0, 1, 5], [0, 5, 4],
        ];

        $name = "mock-{$subject->value}";
        $out = "solid {$name}\n";

        foreach ($triangles as [$a, $b, $c]) {
            [$ax, $ay, $az] = $vertices[$a];
            [$bx, $by, $bz] = $vertices[$b];
            [$cx, $cy, $cz] = $vertices[$c];

            $ux = $bx - $ax;
            $uy = $by - $ay;
            $uz = $bz - $az;
            $vx = $cx - $ax;
            $vy = $cy - $ay;
            $vz = $cz - $az;
            $nx = $uy * $vz - $uz * $vy;
            $ny = $uz * $vx - $ux * $vz;
            $nz = $ux * $vy - $uy * $vx;
            $length = sqrt($nx * $nx + $ny * $ny + $nz * $nz) ?: 1.0;

            $out .= sprintf("facet normal %.6f %.6f %.6f\n  outer loop\n", $nx / $length, $ny / $length, $nz / $length);
            foreach ([[$ax, $ay, $az], [$bx, $by, $bz], [$cx, $cy, $cz]] as [$x, $y, $z]) {
                $out .= sprintf("    vertex %.6f %.6f %.6f\n", $x, $y, $z);
            }
            $out .= "  endloop\nendfacet\n";
        }

        return $out."endsolid {$name}\n";
    }
```

In `app/Services/ModelProviders/MockProvider.php`: remove `final` from the class declaration (so tests can subclass it), return `printModelUrl: "mock://print/{$job['subject']}"` in the Succeeded `ProviderResult`, and replace `download()` with:

```php
    public function download(string $url): string
    {
        if (! preg_match('#^mock://(model|thumbnail|print)/(person|pet|object)$#', $url, $m)) {
            throw new PermanentProviderException("Unsupported mock asset URL [{$url}].");
        }

        $subject = Subject::from($m[2]);

        return match ($m[1]) {
            'model' => MockAssets::glb($subject),
            'thumbnail' => MockAssets::thumbnail($subject),
            'print' => MockAssets::stl($subject),
        };
    }
```

- [ ] **Step 5: GenerateCreation stores the STL**

Edits to `app/Jobs/GenerateCreation.php`:

1. In `handle()` change the Succeeded arm to
`ProviderState::Succeeded => $this->succeed($creation, $provider, $result->modelUrl, $result->thumbnailUrl, $result->printModelUrl),`
2. Change the `succeed` signature to `private function succeed(Creation $creation, ModelProvider $provider, ?string $modelUrl, ?string $thumbnailUrl, ?string $printUrl): void`.
3. After the thumbnail block, before the conditional `update`, add:

```php
        $printPath = null;

        if ($printUrl) {
            $printPath = "creations/{$creation->id}/print.stl";
            $disk->put($printPath, $provider->download($printUrl));
        }
```
4. Add `'print_model_path' => $printPath,` to the conditional update's array (after `'thumbnail_path'`), and change the loser cleanup line to `$disk->delete(array_filter([$modelPath, $thumbPath, $printPath]));`.

- [ ] **Step 6: Meshy provider, config and binding**

In `config/models.php` add inside the returned array:

```php
    'meshy' => [
        // Per HTTP call. Must stay well below the GenerateCreation job timeout (60 s), because
        // one run can download up to three files.
        'timeout_seconds' => 20,
        'default_polycount' => 30000,
    ],
```
and change the `provider` comment to `// Which ModelProvider implementation to use: "mock" or "meshy".`

`app/Services/ModelProviders/MeshyProvider.php`:

```php
<?php

namespace App\Services\ModelProviders;

use App\Models\Style;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class MeshyProvider implements ModelProvider
{
    private const BASE = 'https://api.meshy.ai/openapi/v1';

    public const BUILD_FAILED = 'The 3D model could not be built from this image.';

    public const UNAVAILABLE = '3D building is temporarily unavailable. Please try again later.';

    public function start(string $imagePath, Style $style): string
    {
        $bytes = is_file($imagePath) ? file_get_contents($imagePath) : false;

        if ($bytes === false) {
            throw new PermanentProviderException('We could not read the image.');
        }

        $payload = [
            // The approved image is always our own re-encoded JPEG.
            'image_url' => 'data:image/jpeg;base64,'.base64_encode($bytes),
            'should_texture' => true,
            'enable_pbr' => false,
            'should_remesh' => true,
            'target_polycount' => (int) ($style->provider_params['target_faces'] ?? config('models.meshy.default_polycount')),
            'target_formats' => ['glb', 'stl'],
        ];

        if ($model = config('services.meshy.model')) {
            $payload['ai_model'] = $model;
        }

        $response = $this->send(fn (PendingRequest $http) => $http->post(self::BASE.'/image-to-3d', $payload));
        $this->failOnError($response);

        $id = $response->json('result');

        if (! is_string($id) || $id === '') {
            Log::warning('Meshy did not return a task id.', ['status' => $response->status()]);

            throw new PermanentProviderException(self::BUILD_FAILED);
        }

        return $id;
    }

    public function status(string $providerJobId): ProviderResult
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get(self::BASE.'/image-to-3d/'.rawurlencode($providerJobId)));

        if ($response->status() === 404) {
            return new ProviderResult(ProviderState::Failed, error: self::BUILD_FAILED);
        }

        $this->failOnError($response);

        $progress = $response->json('progress');
        $progress = is_numeric($progress) ? (int) $progress : null;

        return match ($response->json('status')) {
            'SUCCEEDED' => new ProviderResult(
                ProviderState::Succeeded,
                modelUrl: $response->json('model_urls.glb'),
                thumbnailUrl: $response->json('thumbnail_url'),
                progress: 100,
                printModelUrl: $response->json('model_urls.stl'),
            ),
            'FAILED', 'CANCELED' => $this->failed($providerJobId, $response),
            'IN_PROGRESS' => new ProviderResult(ProviderState::Running, progress: $progress),
            default => new ProviderResult(ProviderState::Pending, progress: $progress),
        };
    }

    /** Meshy asset links expire, so the caller always downloads. Only Meshy hosts over https. */
    public function download(string $url): string
    {
        if (! $this->isMeshyAssetUrl($url)) {
            throw new PermanentProviderException('Refusing to download from an unexpected address.');
        }

        try {
            $response = Http::connectTimeout(10)->timeout((int) config('models.meshy.timeout_seconds'))->get($url);
        } catch (ConnectionException $e) {
            throw new TransientProviderException('The 3D service could not be reached.', 0, $e);
        }

        if ($response->successful()) {
            return $response->body();
        }

        if ($response->status() >= 500 || $response->status() === 429 || $response->status() === 408) {
            throw new TransientProviderException("The 3D service is busy (HTTP {$response->status()}).");
        }

        throw new PermanentProviderException(self::BUILD_FAILED);
    }

    private function isMeshyAssetUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');

        return ($parts['scheme'] ?? '') === 'https'
            && ($host === 'meshy.ai' || str_ends_with($host, '.meshy.ai'));
    }

    private function failed(string $taskId, Response $response): ProviderResult
    {
        // The provider's own wording can be technical; log it for the operator, show a plain line.
        Log::warning('Meshy task failed.', [
            'task' => $taskId,
            'status' => $response->json('status'),
            'message' => $response->json('task_error.message'),
        ]);

        return new ProviderResult(ProviderState::Failed, error: self::BUILD_FAILED);
    }

    /** @param callable(PendingRequest): Response $call */
    private function send(callable $call): Response
    {
        $key = config('services.meshy.key');

        if (! $key) {
            Log::critical('Meshy API key is not configured; 3D building is unavailable.');

            throw new PermanentProviderException(self::UNAVAILABLE);
        }

        try {
            return $call(Http::withToken($key)->connectTimeout(10)->timeout((int) config('models.meshy.timeout_seconds')));
        } catch (ConnectionException $e) {
            throw new TransientProviderException('The 3D service could not be reached.', 0, $e);
        }
    }

    private function failOnError(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();

        // Our account is the problem (bad key, out of credits, no access): refund the customer,
        // tell the operator. Only the status is logged, never bodies or keys.
        if (in_array($status, [401, 402, 403], true)) {
            Log::critical('Meshy account problem: 3D builds are failing.', ['status' => $status]);

            throw new PermanentProviderException(self::UNAVAILABLE);
        }

        if ($status === 429 || $status === 408 || $status >= 500) {
            throw new TransientProviderException("The 3D service is busy (HTTP {$status}).");
        }

        Log::warning('Meshy rejected a request.', ['status' => $status]);

        throw new PermanentProviderException(self::BUILD_FAILED);
    }
}
```

In `app/Providers/CreatorServiceProvider.php` add `use App\Services\ModelProviders\MeshyProvider;` and the arm `'meshy' => app(MeshyProvider::class),` to the `ModelProvider` match.

- [ ] **Step 7: Run tests and commit**

```bash
php artisan test tests/Feature/MeshyProviderTest.php tests/Feature/MockProviderTest.php tests/Feature/GenerateCreationTest.php
php artisan test
./vendor/bin/pint app config tests/Feature/MeshyProviderTest.php tests/Feature/MockProviderTest.php tests/Feature/GenerateCreationTest.php
git add app config tests
git commit -m "feat: add MeshyProvider and store the STL print file

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```
Expected: PASS. `MockProviderTest`'s existing GLB and thumbnail tests must still pass unchanged.

---

### Task 13: Smoke command, README and final verification

**Files:**
- Create: `app/Console/Commands/SmokeProviders.php`
- Modify: `README.md`, `docs/superpowers/specs/2026-10-01-two-stage-generation-design.md` (status line only)
- Test: `tests/Feature/SmokeProvidersTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1 to 12.
- Produces: `php artisan providers:smoke {photo} {style} {--subject=person} {--skip-3d}`: runs ONE restyle and (unless `--skip-3d`) ONE 3D build with the configured providers and saves the results under the private disk's `smoke/{timestamp}/`. It asks for confirmation first because real runs cost money. It is for the owner's manual checks and never runs in the test suite against real services.

- [ ] **Step 1: Write the failing test**

`tests/Feature/SmokeProvidersTest.php`:

```php
<?php

use App\Models\Style;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config([
        'stylizer.provider' => 'mock',
        'stylizer.mock.fail_rate' => 0,
        'models.provider' => 'mock',
        'models.mock.delay_seconds' => 0,
        'models.mock.fail_rate' => 0,
    ]);
    Style::factory()->create(['subject' => 'person', 'look' => 'chibi']);
    $this->photo = tempnam(sys_get_temp_dir(), 'photo');
    file_put_contents($this->photo, fakeJpegBytes());
});

it('runs one restyle and one 3D build and saves the results', function () {
    $this->artisan('providers:smoke', ['photo' => $this->photo, 'style' => 'chibi'])
        ->expectsConfirmation('This makes paid API calls with your configured keys. Continue?', 'yes')
        ->assertSuccessful();

    $files = collect(Storage::disk('local')->allFiles())->filter(fn ($f) => str_starts_with($f, 'smoke/'));

    expect($files->contains(fn ($f) => str_ends_with($f, '/restyled.png')))->toBeTrue()
        ->and($files->contains(fn ($f) => str_ends_with($f, '/model.glb')))->toBeTrue()
        ->and($files->contains(fn ($f) => str_ends_with($f, '/print.stl')))->toBeTrue();
});

it('can stop after the restyle step', function () {
    $this->artisan('providers:smoke', ['photo' => $this->photo, 'style' => 'chibi', '--skip-3d' => true])
        ->expectsConfirmation('This makes paid API calls with your configured keys. Continue?', 'yes')
        ->assertSuccessful();

    $files = collect(Storage::disk('local')->allFiles())->filter(fn ($f) => str_starts_with($f, 'smoke/'));

    expect($files->contains(fn ($f) => str_ends_with($f, '/restyled.png')))->toBeTrue()
        ->and($files->contains(fn ($f) => str_ends_with($f, '/model.glb')))->toBeFalse();
});

it('does nothing without confirmation, and rejects unknown styles or missing photos', function () {
    $this->artisan('providers:smoke', ['photo' => $this->photo, 'style' => 'chibi'])
        ->expectsConfirmation('This makes paid API calls with your configured keys. Continue?', 'no')
        ->assertFailed();

    $this->artisan('providers:smoke', ['photo' => $this->photo, 'style' => 'nonexistent'])
        ->expectsConfirmation('This makes paid API calls with your configured keys. Continue?', 'yes')
        ->assertFailed();

    $this->artisan('providers:smoke', ['photo' => '/no/such/photo.jpg', 'style' => 'chibi'])
        ->assertFailed();

    expect(collect(Storage::disk('local')->allFiles())->filter(fn ($f) => str_starts_with($f, 'smoke/')))->toBeEmpty();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/SmokeProvidersTest.php`
Expected: FAIL.

- [ ] **Step 3: The command**

`app/Console/Commands/SmokeProviders.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\Style;
use App\Services\ImageSanitizer;
use App\Services\ModelProviders\ModelProvider;
use App\Services\ModelProviders\ProviderState;
use App\Services\Stylizers\ImageStylizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SmokeProviders extends Command
{
    protected $signature = 'providers:smoke
        {photo : Path to a photo on this machine}
        {style : A style id, or a look such as chibi, clay or sleepy}
        {--subject=person : Subject to use when the style is given as a look}
        {--skip-3d : Only run the restyle step}';

    protected $description = 'Run ONE real restyle and ONE real 3D build with the configured providers (costs money)';

    private const POLL_SECONDS = 5;

    private const MAX_WAIT_SECONDS = 600;

    public function handle(ImageSanitizer $sanitizer, ImageStylizer $stylizer, ModelProvider $provider): int
    {
        $photo = (string) $this->argument('photo');

        if (! is_file($photo)) {
            $this->error("Photo not found: {$photo}");

            return self::FAILURE;
        }

        if (! $this->confirm('This makes paid API calls with your configured keys. Continue?')) {
            return self::FAILURE;
        }

        $style = $this->findStyle();

        if (! $style) {
            $this->error('No such style. Use a style id, or a look like chibi (with --subject).');

            return self::FAILURE;
        }

        $disk = Storage::disk('local');
        $dir = 'smoke/'.now()->format('Ymd-His');
        $this->line('Providers: restyle='.config('stylizer.provider').', 3D='.config('models.provider'));

        try {
            $disk->put("{$dir}/original.jpg", $sanitizer->sanitize($photo));

            $started = microtime(true);
            $this->info("Restyling with {$style->subject->value} {$style->look}...");
            $png = $stylizer->stylize($disk->path("{$dir}/original.jpg"), $style);
            $disk->put("{$dir}/restyled.png", $png);
            $this->line(sprintf('  done in %.1fs, %s KB -> %s', microtime(true) - $started, number_format(strlen($png) / 1024, 1), $disk->path("{$dir}/restyled.png")));

            if ($this->option('skip-3d')) {
                return self::SUCCESS;
            }

            $disk->put("{$dir}/restyled.jpg", $sanitizer->sanitize($disk->path("{$dir}/restyled.png")));

            $this->info('Building the 3D model...');
            $taskId = $provider->start($disk->path("{$dir}/restyled.jpg"), $style);
            $this->line("  task: {$taskId}");

            $result = $this->waitFor($provider, $taskId);

            if ($result->state === ProviderState::Failed) {
                $this->error('3D build failed: '.($result->error ?? 'unknown error'));

                return self::FAILURE;
            }

            $disk->put("{$dir}/model.glb", $provider->download((string) $result->modelUrl));
            $result->printModelUrl && $disk->put("{$dir}/print.stl", $provider->download($result->printModelUrl));
            $result->thumbnailUrl && $disk->put("{$dir}/thumbnail.png", $provider->download($result->thumbnailUrl));
            $this->info('Saved to '.$disk->path($dir));
        } catch (Throwable $e) {
            $this->error(class_basename($e).': '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function findStyle(): ?Style
    {
        $given = (string) $this->argument('style');

        return ctype_digit($given)
            ? Style::find((int) $given)
            : Style::where('look', $given)->where('subject', $this->option('subject'))->first();
    }

    private function waitFor(ModelProvider $provider, string $taskId): \App\Services\ModelProviders\ProviderResult
    {
        $deadline = time() + self::MAX_WAIT_SECONDS;

        while (true) {
            $result = $provider->status($taskId);

            $this->line('  '.strtolower($result->state->name).($result->progress !== null ? " {$result->progress}%" : ''));

            if (in_array($result->state, [ProviderState::Succeeded, ProviderState::Failed], true) || time() > $deadline) {
                return $result;
            }

            // A manual command, not a queue worker: sleeping here is fine.
            sleep(self::POLL_SECONDS);
        }
    }
}
```

If the timeout branch returns a non-final `Pending/Running` result, treat that as a failure in the caller: after `waitFor`, add `if (! in_array($result->state, [ProviderState::Succeeded, ProviderState::Failed], true)) { $this->error('Timed out waiting for the 3D build.'); return self::FAILURE; }` before the Failed check.

- [ ] **Step 4: README and spec status**

In `README.md` add a section **"Two-stage generation"** after the existing "Model provider" section, covering: the flow in four lines (upload, preview, approve, 3D); the `.env` settings table from the spec's configuration section (`STYLIZER_PROVIDER`, `STYLIZER_ENABLED`, `OPENAI_API_KEY`, `OPENAI_IMAGE_MODEL`, `MODEL_PROVIDER`, `MESHY_API_KEY`, `MESHY_MODEL`, `CREDITS_RESTYLE_COST`, `STYLIZER_RETENTION_DAYS`, `STYLIZER_DAILY_LIMIT`, `STYLIZER_MOCK_FAIL_RATE`); how to try real providers (set the two keys and the two provider names, run `php artisan providers:smoke photo.jpg chibi` and inspect the saved files under `storage/app/private/smoke/`; it costs money); that production refuses `mock` providers; the scheduler requirement (`php artisan schedule:work` in dev, a cron `* * * * * php artisan schedule:run` in production) because the sweeper and the daily prune are scheduled; that `DB_QUEUE_RETRY_AFTER` must stay above 240 (default 300); the privacy note (photos go to OpenAI and Meshy, the original is deleted at approval, unapproved previews after 7 days); and a **launch checklist**: run the smoke command with your keys, test the eight style prompts that have not been tried by hand in ChatGPT (person Realistic and Cartoon, all pet styles, both object styles), confirm Meshy STL output on your plan, measure real per-run costs and set credit prices to cover both vendors.

Change the status line of the spec to `Status: Approved (implemented)`.

- [ ] **Step 5: Final verification**

```bash
php artisan test
./vendor/bin/pint app config routes tests/Feature
npx vue-tsc --noEmit
npm run build
npx eslint resources/js
php artisan creations:sweep
php artisan stylizations:prune
php artisan schedule:list
```
Expected: all tests PASS; Pint clean on the changed files; only the 2 known type errors; build passes; eslint reports nothing new for files changed on this branch; both commands report 0; `schedule:list` shows `creations:sweep` every five minutes and `stylizations:prune` daily.

Then the **end-to-end browser run** with a real queue worker and the mock providers, covering all of the following with concrete observations (what you saw, console state, numbers) written to your report:
1. Landing page: four steps, demo viewer renders, no console errors.
2. Register a throwaway user (20 credits): `/create` shows the preview fee and the privacy note.
3. Upload a 512 px or larger image, pick a style, submit: the preview page shows the making state, then (polling) the side-by-side comparison; the restyled image carries the mock border; balance is 19.
4. **Try again**: new preview, balance 18, old preview gone.
5. **Build 3D model**: creation page building state, then the viewer renders the cube and **Download GLB** returns a file starting with `glTF`; balance is 18 minus the style price.
6. Restyle failure: set `STYLIZER_MOCK_FAIL_RATE=1` and restart the worker; upload; the failed state shows the refund note and the balance is restored; restore `STYLIZER_MOCK_FAIL_RATE=0`.
7. 3D failure: set `MODEL_MOCK_FAIL_RATE=1`, restart the worker, approve a preview; the creation fails with the refund text, the preview fee stays spent, balance is correct; **Try again** creates a new creation; restore `MODEL_MOCK_FAIL_RATE=0`.
8. My Creations shows the "Previews waiting for approval" strip for an unapproved preview; Credits shows "Preview", "Preview refund", "Model generation" and "Refund" rows with the right signs.
9. A second throwaway user gets 403 on the first user's `/stylizations/{id}`, its files, and `/creations/{id}`; the old direct `POST /creations` returns 405.
10. Stop the server while a preview is polling: the page shows the "couldn't check" message, no modal spam; restart and click **Check again**.
11. `php artisan creations:sweep` and `stylizations:prune` against the dev DB report 0 after cleanup.

Cleanup: delete every throwaway user, their stylizations, creations, ledger rows, sessions, jobs and files under `storage/app/private`; restore `.env` values you changed (`grep` to prove it); stop all servers and workers; list dev DB row counts before and after (must match). Never run `migrate:fresh`.

You cannot run the paid smoke command (no keys): say so in the report and leave it for the owner.

- [ ] **Step 6: Commit**

```bash
git add app tests README.md docs
git commit -m "feat: add providers:smoke command, document two-stage generation

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```
Do not push.

---

## Self-Review (spec coverage)

| Spec section | Covered by |
|---|---|
| 4 Flow (restyle, preview, approve, retry, 3D retry) | Tasks 4, 6, 7, 8, 9, 10 |
| 5 Data model (`stylizations`, ledger link, `print_model_path`, enum) | Task 1 |
| 6 Credits (restyle fee, per-stage refunds, `spendForStylization`/`refundStylization`) | Task 2, 4, 6 |
| 7.1 `ImageStylizer`, `OpenAiStylizer`, `MockStylizer` and error mapping | Tasks 3, 11 |
| 7.2 `MeshyProvider`, `printModelUrl`, host allow-list, mock STL | Task 12 |
| 8 Jobs: `RestylePhoto`, deadline, conditional writes, lock and timing invariant, sweeper, prune | Tasks 3 (retry_after), 4, 5 |
| 9 Services: create, approve (lock order, idempotent), retry, discard, `createFromImage`, 3D retry, upload route removal | Tasks 4, 6, 8 |
| 10 Pages and routes, polling fixes, quality bar | Tasks 7, 8, 9, 10 |
| 11 Errors and refunds table | Tasks 4, 6, 11, 12 (each row has a test) |
| 12 Security: keys and logging, retention, daily limit, kill switch, production mock guard, throttles, policies, no brand names | Tasks 3, 4, 5, 7, 11, 12 (brand-name guard already exists in `ModelsTest`) |
| 13 Configuration | Task 3 (+ README in 13) |
| 14 Testing incl. smoke command and browser run | Every task; Task 13 |
| 15 Build order | Task order mirrors it |
| 16 Assumptions to verify | Tasks 11 and 12 Step 1 (docs re-check), Task 13 launch checklist |

**Deviations from the spec, deliberate:**
1. The Create page shows only the preview fee as the required balance and warns softly about the 3D price, as decided in design section 3.
2. `StylizationService::retry` and `create` check the daily limit and kill switch before opening the transaction (documented in the code and the global constraints) so the charge's row lock keeps its snapshot guarantee.
3. The oversize/`maxMs` polling limit (12 minutes) is a frontend constant, not a configuration value.
4. Meshy's provider error text is logged but never shown to customers; they see a fixed plain message.
