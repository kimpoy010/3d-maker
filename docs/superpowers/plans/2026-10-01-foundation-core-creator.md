# Foundation + Core Creator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build sub-project 1 of the 3D Maker app: accounts, a photo → 3D model creation flow (queued, via a swappable provider adapter with a mock implementation), an in-browser 3D viewer with download, and a credits ledger.

**Architecture:** Laravel 12 monolith using the official Vue starter kit (Inertia + Vue 3 + TypeScript + Tailwind + shadcn-vue, auth via Fortify). A `ModelProvider` interface hides the image-to-3D API; `MockProvider` is the only implementation now. Generation runs in a queued `GenerateCreation` job that polls the provider by releasing itself back onto the queue. Credits are an append-only ledger; failed generations refund idempotently. The browser polls the creation page for status.

**Tech Stack:** PHP 8.4, Laravel 12, MySQL 8.4 (dev) / SQLite in-memory (tests), Inertia, Vue 3 + TypeScript, Tailwind, Pest, Three.js (GLTFLoader, OrbitControls), GD.

**Spec:** `docs/superpowers/specs/2026-10-01-foundation-core-creator-design.md`

## Global Constraints

- Stack is Laravel + Inertia/Vue + MySQL; no websockets (client polling every 3 s).
- Provider interface is `ModelProvider`; selection via `config/models.php` → `'provider' => env('MODEL_PROVIDER', 'mock')`; only `MockProvider` is built in this plan.
- Generation hard timeout is about 10 minutes (`models.timeout_seconds` = 600) → creation `failed` ("timed out") and refunded.
- Credits ledger is append-only; balance = `SUM(delta)`; unique (`creation_id`, `reason`) keeps refunds idempotent; free credits are granted at signup.
- Uploads and models live on the private (`local`) disk and are served only through authorized controller routes (owner only), never public URLs.
- Uploaded photos are re-encoded server-side (strips metadata); generate endpoint is rate limited per user.
- Out of scope: gallery, sharing, real payments, shop/orders, admin, partners, moderation, real provider.
- PHP floor 8.4, Node 24, MySQL 8.4 (what is installed under Laragon). Tests live under `tests/Feature` (Pest; DB-backed via the kit's `RefreshDatabase` setup).
- Commit messages end with the line `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- All Inertia pages are TypeScript `<script setup lang="ts">` and use plain URL strings (no Ziggy/Wayfinder).

## File Structure

```
app/
  Enums/{Subject,CreationStatus,LedgerReason}.php
  Exceptions/{InsufficientCreditsException,InvalidImageException}.php
  Http/Controllers/{CreationController,CreationFileController,CreditController,SampleController}.php
  Http/Requests/StoreCreationRequest.php
  Http/Resources/CreationResource.php
  Jobs/GenerateCreation.php
  Listeners/GrantSignupCredits.php
  Models/{Style,Creation,CreditLedgerEntry}.php
  Policies/CreationPolicy.php
  Providers/CreatorServiceProvider.php
  Services/
    CreationService.php
    ImageSanitizer.php
    Credits/CreditService.php
    ModelProviders/{ModelProvider,ProviderResult,ProviderState,MockProvider,MockAssets,
                    ProviderException,TransientProviderException,PermanentProviderException}.php
config/{credits,models}.php
database/{migrations (3 new), factories/{Style,Creation}Factory.php, seeders/StyleSeeder.php}
resources/js/
  components/ModelViewer.vue
  pages/{Welcome,Create,Credits}.vue, pages/creations/{Index,Show}.vue
routes/web.php
tests/Feature/*.php
```

---

### Task 1: Scaffold, database, and GitHub repo

**Files:**
- Create: entire Laravel scaffold at the project root (docs/ already exists and is preserved)
- Modify: `.env`, `.env.example`
- Modify: `docs/superpowers/specs/2026-10-01-foundation-core-creator-design.md` (already updated; verify only)

**Interfaces:**
- Produces: a booting Laravel 12 app with Fortify auth, Inertia/Vue pages, Pest, MySQL database `three_d_maker`, git repo on `main` with `origin` = `https://github.com/kimpoy010/3d-maker.git`.

- [ ] **Step 1: Scaffold into a temp folder and move it up**

The folder is not empty (it holds `docs/`), so scaffold elsewhere then copy. The installed `laravel` installer (5.2.1) predates the Vue starter kit, so use Composer directly.

```bash
cd /c/laragon/www/3d-maker
composer create-project laravel/vue-starter-kit _scaffold --no-interaction
cp -r _scaffold/. .
rm -rf _scaffold
php artisan optimize:clear
composer show laravel/framework | head -3
```
Expected: `versions : * v12.x`. If it is not 12.x, stop and tell the user.

- [ ] **Step 2: Create the MySQL database and point `.env` at it**

```bash
/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS three_d_maker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
```

Edit `.env`: set `APP_NAME="3D Maker"`, `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=three_d_maker`, `DB_USERNAME=root`, `DB_PASSWORD=` (Laragon default: blank). Confirm `QUEUE_CONNECTION=database`. Append these lines to **both** `.env` and `.env.example`:

```
MODEL_PROVIDER=mock
MODEL_MOCK_DELAY_SECONDS=6
MODEL_MOCK_FAIL_RATE=0
CREDITS_SIGNUP=20
CREDITS_TOPUP=25
```
In `.env.example` also set `APP_NAME="3D Maker"`, `DB_CONNECTION=mysql`, `DB_DATABASE=three_d_maker`.

- [ ] **Step 3: Install JS dependencies and verify the baseline**

```bash
php artisan migrate
npm install
npm run build
php artisan test
```
Expected: migrations succeed on MySQL, build succeeds, all kit tests PASS.

- [ ] **Step 4: Inspect the scaffold files later tasks edit**

Read these and keep their exact names in mind (later tasks mirror them): `routes/web.php`, `bootstrap/providers.php`, `database/seeders/DatabaseSeeder.php`, `tests/Pest.php`, `resources/js/pages/Dashboard.vue` (import header for `AppLayout`/`BreadcrumbItem`/`Head`), `resources/js/pages/Welcome.vue` (how it reads `auth`/`canRegister`), `resources/js/components/AppSidebar.vue` (nav item array), `resources/js/types/index.d.ts` (`SharedData`, `BreadcrumbItem`). Run `ls resources/js/layouts resources/js/components/ui` and note the `AppLayout` path and available UI components. If an import path in a later task differs from what you see here, use what the scaffold has.

- [ ] **Step 5: Initialize git and connect the remote**

```bash
cd /c/laragon/www/3d-maker
git init -b main
git remote add origin https://github.com/kimpoy010/3d-maker.git
git add -A
git status --short | head -20   # confirm .env and node_modules are NOT listed
git commit -m "chore: scaffold Laravel 12 Vue starter kit with spec and plan

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 6: Push (confirm with the user first)**

Pushing is outward-facing. Ask the user once to confirm, then:

```bash
git push -u origin main
```

---

### Task 2: Enums, migrations, models, factories, style seeder

**Files:**
- Create: `app/Enums/Subject.php`, `app/Enums/CreationStatus.php`, `app/Enums/LedgerReason.php`
- Create: `database/migrations/2026_10_01_000001_create_styles_table.php`, `..._000002_create_creations_table.php`, `..._000003_create_credit_ledger_table.php`
- Create: `app/Models/Style.php`, `app/Models/Creation.php`, `app/Models/CreditLedgerEntry.php`
- Create: `database/factories/StyleFactory.php`, `database/factories/CreationFactory.php`, `database/seeders/StyleSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/ModelsTest.php`

**Interfaces:**
- Produces: `Subject` (`Person|Pet|Object`, values `person|pet|object`); `CreationStatus` (`Queued|Processing|Succeeded|Failed`, method `isFinished(): bool`); `LedgerReason` (`Signup|Topup|Generation|Refund`, values `signup|topup|generation|refund`); models `Style` (casts `subject`→`Subject`, `provider_params`→array, `active`→bool; scope `active()`), `Creation` (fillable: `user_id, style_id, source_image_path, status, provider_job_id, model_path, thumbnail_path, error, cost_credits, progress`; casts `status`→`CreationStatus`; relations `user()`, `style()`), `CreditLedgerEntry` (table `credit_ledger`; fillable `user_id, delta, reason, creation_id`; cast `reason`→`LedgerReason`; `created_at` only); factories `Style::factory()`, `Creation::factory()`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/ModelsTest.php`:

```php
<?php

use App\Enums\CreationStatus;
use App\Enums\Subject;
use App\Models\Creation;
use App\Models\Style;
use App\Models\User;
use Database\Seeders\StyleSeeder;

it('casts style attributes', function () {
    $style = Style::factory()->create(['subject' => Subject::Pet, 'provider_params' => ['texture' => true]]);

    $fresh = Style::find($style->id);

    expect($fresh->subject)->toBe(Subject::Pet)
        ->and($fresh->provider_params)->toBe(['texture' => true])
        ->and($fresh->active)->toBeTrue();
});

it('filters active styles', function () {
    Style::factory()->create();
    Style::factory()->create(['active' => false]);

    expect(Style::active()->count())->toBe(1);
});

it('creates a creation that belongs to a user and style', function () {
    $creation = Creation::factory()->create();

    expect($creation->status)->toBe(CreationStatus::Queued)
        ->and($creation->user)->toBeInstanceOf(User::class)
        ->and($creation->style)->toBeInstanceOf(Style::class)
        ->and($creation->status->isFinished())->toBeFalse();
});

it('treats succeeded and failed as finished', function () {
    expect(CreationStatus::Succeeded->isFinished())->toBeTrue()
        ->and(CreationStatus::Failed->isFinished())->toBeTrue()
        ->and(CreationStatus::Processing->isFinished())->toBeFalse();
});

it('seeds styles idempotently', function () {
    $this->seed(StyleSeeder::class);
    $count = Style::count();
    $this->seed(StyleSeeder::class);

    expect($count)->toBeGreaterThan(5)->and(Style::count())->toBe($count);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/ModelsTest.php`
Expected: FAIL (classes not found).

- [ ] **Step 3: Enums**

`app/Enums/Subject.php`:

```php
<?php

namespace App\Enums;

enum Subject: string
{
    case Person = 'person';
    case Pet = 'pet';
    case Object = 'object';
}
```

`app/Enums/CreationStatus.php`:

```php
<?php

namespace App\Enums;

enum CreationStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed], true);
    }
}
```

`app/Enums/LedgerReason.php`:

```php
<?php

namespace App\Enums;

enum LedgerReason: string
{
    case Signup = 'signup';
    case Topup = 'topup';
    case Generation = 'generation';
    case Refund = 'refund';
}
```

- [ ] **Step 4: Migrations**

`database/migrations/2026_10_01_000001_create_styles_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('styles', function (Blueprint $table) {
            $table->id();
            $table->string('subject');
            $table->string('name');
            $table->string('look');
            $table->json('provider_params');
            $table->unsignedSmallInteger('credit_cost')->default(5);
            $table->boolean('active')->default(true);
            $table->string('preview_image')->nullable();
            $table->timestamps();

            $table->unique(['subject', 'look']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('styles');
    }
};
```

`database/migrations/2026_10_01_000002_create_creations_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('style_id')->constrained();
            $table->string('source_image_path');
            $table->string('status')->default('queued')->index();
            $table->string('provider_job_id')->nullable();
            $table->string('model_path')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('cost_credits');
            $table->unsignedTinyInteger('progress')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creations');
    }
};
```

`database/migrations/2026_10_01_000003_create_credit_ledger_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('delta');
            $table->string('reason');
            $table->foreignId('creation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            // At most one generation row and one refund row per creation.
            // NULL creation_ids (signup/topup) never collide.
            $table->unique(['creation_id', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledger');
    }
};
```

- [ ] **Step 5: Models**

`app/Models/Style.php`:

```php
<?php

namespace App\Models;

use App\Enums\Subject;
use Database\Factories\StyleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Style extends Model
{
    /** @use HasFactory<StyleFactory> */
    use HasFactory;

    protected $fillable = ['subject', 'name', 'look', 'provider_params', 'credit_cost', 'active', 'preview_image'];

    protected function casts(): array
    {
        return [
            'subject' => Subject::class,
            'provider_params' => 'array',
            'active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
```

`app/Models/Creation.php`:

```php
<?php

namespace App\Models;

use App\Enums\CreationStatus;
use Database\Factories\CreationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Creation extends Model
{
    /** @use HasFactory<CreationFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'style_id', 'source_image_path', 'status', 'provider_job_id',
        'model_path', 'thumbnail_path', 'error', 'cost_credits', 'progress',
    ];

    protected function casts(): array
    {
        return ['status' => CreationStatus::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function style(): BelongsTo
    {
        return $this->belongsTo(Style::class);
    }
}
```

`app/Models/CreditLedgerEntry.php`:

```php
<?php

namespace App\Models;

use App\Enums\LedgerReason;
use Illuminate\Database\Eloquent\Model;

class CreditLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'credit_ledger';

    protected $fillable = ['user_id', 'delta', 'reason', 'creation_id'];

    protected function casts(): array
    {
        return ['reason' => LedgerReason::class];
    }
}
```

- [ ] **Step 6: Factories and seeder**

`database/factories/StyleFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\Subject;
use App\Models\Style;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Style> */
class StyleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subject' => Subject::Person,
            'name' => fake()->unique()->words(2, true),
            'look' => fake()->unique()->slug(2),
            'provider_params' => ['texture' => true],
            'credit_cost' => 5,
            'active' => true,
            'preview_image' => null,
        ];
    }
}
```

`database/factories/CreationFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\CreationStatus;
use App\Models\Creation;
use App\Models\Style;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Creation> */
class CreationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'style_id' => Style::factory(),
            'source_image_path' => 'uploads/test.jpg',
            'status' => CreationStatus::Queued,
            'cost_credits' => 5,
        ];
    }
}
```

`database/seeders/StyleSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Enums\Subject;
use App\Models\Style;
use Illuminate\Database\Seeder;

class StyleSeeder extends Seeder
{
    public function run(): void
    {
        $looks = [
            'realistic' => ['Realistic', 6, ['texture' => true, 'target_faces' => 50000]],
            'cartoon' => ['Cartoon', 5, ['texture' => true, 'target_faces' => 30000]],
            'clay' => ['Clay', 5, ['texture' => false, 'target_faces' => 30000]],
            'chibi' => ['Chibi', 5, ['texture' => true, 'target_faces' => 20000]],
        ];

        $matrix = [
            Subject::Person->value => ['realistic', 'cartoon', 'clay', 'chibi'],
            Subject::Pet->value => ['realistic', 'cartoon', 'clay'],
            Subject::Object->value => ['realistic', 'clay'],
        ];

        foreach ($matrix as $subject => $subjectLooks) {
            foreach ($subjectLooks as $look) {
                [$label, $cost, $params] = $looks[$look];

                Style::updateOrCreate(
                    ['subject' => $subject, 'look' => $look],
                    [
                        'name' => $label,
                        'credit_cost' => $cost,
                        'provider_params' => $params + ['look' => $look],
                        'active' => true,
                    ],
                );
            }
        }
    }
}
```

Read `database/seeders/DatabaseSeeder.php` and, inside `run()`, add `$this->call(StyleSeeder::class);` (keep any existing user-seeding the kit has).

- [ ] **Step 7: Migrate, seed, run tests**

```bash
php artisan migrate
php artisan db:seed --class=StyleSeeder
php artisan test tests/Feature/ModelsTest.php
```
Expected: PASS (5 tests).

- [ ] **Step 8: Commit**

```bash
git add app database tests
git commit -m "feat: add styles, creations, and credit ledger schema with models

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: CreditService and signup credits

**Files:**
- Create: `config/credits.php`, `app/Exceptions/InsufficientCreditsException.php`, `app/Services/Credits/CreditService.php`, `app/Listeners/GrantSignupCredits.php`
- Test: `tests/Feature/CreditServiceTest.php`, `tests/Feature/SignupCreditsTest.php`

**Interfaces:**
- Consumes: `LedgerReason`, `CreditLedgerEntry`, `Creation`, `User` (Task 2).
- Produces:
  - `CreditService::balance(User $user): int`
  - `CreditService::grant(User $user, int $amount, LedgerReason $reason): CreditLedgerEntry`
  - `CreditService::spend(User $user, int $amount, Creation $creation): CreditLedgerEntry` (throws `InsufficientCreditsException`; locks the user row; call inside the caller's transaction)
  - `CreditService::refund(Creation $creation): ?CreditLedgerEntry` (idempotent; null if nothing to refund or already refunded)
  - `InsufficientCreditsException` with public `int $required`, `int $balance`
  - config keys `credits.signup`, `credits.topup`, `credits.stub_topup`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/CreditServiceTest.php`:

```php
<?php

use App\Enums\LedgerReason;
use App\Exceptions\InsufficientCreditsException;
use App\Models\Creation;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Services\Credits\CreditService;

beforeEach(function () {
    $this->credits = app(CreditService::class);
    $this->user = User::factory()->create();
});

it('computes the balance as the sum of ledger deltas', function () {
    expect($this->credits->balance($this->user))->toBe(0);

    $this->credits->grant($this->user, 20, LedgerReason::Signup);
    $this->credits->grant($this->user, 5, LedgerReason::Topup);

    expect($this->credits->balance($this->user))->toBe(25);
});

it('spends credits against a creation', function () {
    $this->credits->grant($this->user, 20, LedgerReason::Signup);
    $creation = Creation::factory()->create(['user_id' => $this->user->id]);

    $entry = $this->credits->spend($this->user, 5, $creation);

    expect($entry->delta)->toBe(-5)
        ->and($entry->reason)->toBe(LedgerReason::Generation)
        ->and($entry->creation_id)->toBe($creation->id)
        ->and($this->credits->balance($this->user))->toBe(15);
});

it('rejects a spend larger than the balance and writes nothing', function () {
    $this->credits->grant($this->user, 3, LedgerReason::Signup);
    $creation = Creation::factory()->create(['user_id' => $this->user->id]);

    expect(fn () => $this->credits->spend($this->user, 5, $creation))
        ->toThrow(InsufficientCreditsException::class);

    expect($this->credits->balance($this->user))->toBe(3)
        ->and(CreditLedgerEntry::where('reason', LedgerReason::Generation)->count())->toBe(0);
});

it('refunds a spend exactly once', function () {
    $this->credits->grant($this->user, 20, LedgerReason::Signup);
    $creation = Creation::factory()->create(['user_id' => $this->user->id]);
    $this->credits->spend($this->user, 5, $creation);

    $first = $this->credits->refund($creation);
    $second = $this->credits->refund($creation);

    expect($first->delta)->toBe(5)
        ->and($second)->toBeNull()
        ->and($this->credits->balance($this->user))->toBe(20)
        ->and(CreditLedgerEntry::where('reason', LedgerReason::Refund)->count())->toBe(1);
});

it('does not refund a creation that was never charged', function () {
    $creation = Creation::factory()->create(['user_id' => $this->user->id]);

    expect($this->credits->refund($creation))->toBeNull()
        ->and($this->credits->balance($this->user))->toBe(0);
});
```

`tests/Feature/SignupCreditsTest.php`:

```php
<?php

use App\Models\User;
use App\Services\Credits\CreditService;
use Illuminate\Auth\Events\Registered;

it('grants signup credits when a user registers', function () {
    config(['credits.signup' => 20]);
    $user = User::factory()->create();

    event(new Registered($user));

    expect(app(CreditService::class)->balance($user))->toBe(20);
});

it('grants signup credits through the registration form', function () {
    config(['credits.signup' => 20]);

    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'new@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect();

    $user = User::where('email', 'new@example.com')->firstOrFail();

    expect(app(CreditService::class)->balance($user))->toBe(20);
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test tests/Feature/CreditServiceTest.php tests/Feature/SignupCreditsTest.php`
Expected: FAIL (classes not found).

- [ ] **Step 3: Config and exception**

`config/credits.php`:

```php
<?php

return [
    // Free credits granted at registration.
    'signup' => (int) env('CREDITS_SIGNUP', 20),

    // Credits granted by the stubbed "Add credits" button.
    'topup' => (int) env('CREDITS_TOPUP', 25),

    // The stub top-up is a free-credits button, so it is off in production by default.
    'stub_topup' => env('CREDITS_STUB_TOPUP', env('APP_ENV') !== 'production'),
];
```

`app/Exceptions/InsufficientCreditsException.php`:

```php
<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientCreditsException extends RuntimeException
{
    public function __construct(public readonly int $required, public readonly int $balance)
    {
        parent::__construct("Insufficient credits: need {$required}, have {$balance}.");
    }
}
```

- [ ] **Step 4: CreditService**

`app/Services/Credits/CreditService.php`:

```php
<?php

namespace App\Services\Credits;

use App\Enums\LedgerReason;
use App\Exceptions\InsufficientCreditsException;
use App\Models\Creation;
use App\Models\CreditLedgerEntry;
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
        return DB::transaction(function () use ($user, $amount, $creation) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $balance = $this->balance($user);
            if ($balance < $amount) {
                throw new InsufficientCreditsException($amount, $balance);
            }

            return CreditLedgerEntry::create([
                'user_id' => $user->id,
                'delta' => -$amount,
                'reason' => LedgerReason::Generation,
                'creation_id' => $creation->id,
            ]);
        });
    }

    /** Give back what a creation cost. Returns null if there is nothing to refund. */
    public function refund(Creation $creation): ?CreditLedgerEntry
    {
        $spent = CreditLedgerEntry::where('creation_id', $creation->id)
            ->where('reason', LedgerReason::Generation)
            ->first();

        if (! $spent) {
            return null;
        }

        try {
            return DB::transaction(function () use ($creation, $spent) {
                $alreadyRefunded = CreditLedgerEntry::where('creation_id', $creation->id)
                    ->where('reason', LedgerReason::Refund)
                    ->exists();

                if ($alreadyRefunded) {
                    return null;
                }

                return CreditLedgerEntry::create([
                    'user_id' => $spent->user_id,
                    'delta' => -$spent->delta,
                    'reason' => LedgerReason::Refund,
                    'creation_id' => $creation->id,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent refund won the race; the unique index kept it to one.
            return null;
        }
    }
}
```

- [ ] **Step 5: Signup listener**

`app/Listeners/GrantSignupCredits.php` (Laravel auto-discovers listeners in `app/Listeners` from the type-hinted event):

```php
<?php

namespace App\Listeners;

use App\Enums\LedgerReason;
use App\Services\Credits\CreditService;
use Illuminate\Auth\Events\Registered;

class GrantSignupCredits
{
    public function __construct(private CreditService $credits) {}

    public function handle(Registered $event): void
    {
        $this->credits->grant($event->user, (int) config('credits.signup'), LedgerReason::Signup);
    }
}
```

- [ ] **Step 6: Run tests**

Run: `php artisan test tests/Feature/CreditServiceTest.php tests/Feature/SignupCreditsTest.php`
Expected: PASS (7 tests). If the registration-form test fails with a 302 to a different place or validation errors, read the scaffold's registration action (`app/Actions/Fortify/CreateNewUser.php`) for the required fields and fix the test payload. If the listener does not fire, confirm `php artisan event:list` shows `GrantSignupCredits` under `Illuminate\Auth\Events\Registered`.

- [ ] **Step 7: Commit**

```bash
git add app config tests
git commit -m "feat: add credit ledger service and signup credit grant

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Provider interface and MockProvider

**Files:**
- Create: `config/models.php`
- Create: `app/Services/ModelProviders/{ModelProvider,ProviderState,ProviderResult,ProviderException,TransientProviderException,PermanentProviderException,MockAssets,MockProvider}.php`
- Create: `app/Providers/CreatorServiceProvider.php`
- Modify: `bootstrap/providers.php`
- Test: `tests/Feature/MockProviderTest.php`

**Interfaces:**
- Consumes: `Style`, `Subject` (Task 2).
- Produces:
  - `interface ModelProvider { start(string $imagePath, Style $style): string; status(string $providerJobId): ProviderResult; download(string $url): string; }` (`download` returns file bytes; real providers fetch over HTTP, the mock generates them. This is an addition to the spec's two-method interface, because provider URLs expire and must be fetched by us.)
  - `enum ProviderState { Pending, Running, Succeeded, Failed }`
  - `final class ProviderResult(ProviderState $state, ?string $modelUrl = null, ?string $thumbnailUrl = null, ?string $error = null, ?int $progress = null)` with readonly public properties
  - `TransientProviderException`, `PermanentProviderException` (both extend `ProviderException extends RuntimeException`)
  - `MockAssets::glb(Subject $subject): string`, `MockAssets::thumbnail(Subject $subject): string`
  - config keys `models.provider`, `models.timeout_seconds` (600), `models.poll_seconds` (3), `models.mock.delay_seconds`, `models.mock.fail_rate`
  - container binding `ModelProvider::class` → `MockProvider` when `models.provider === 'mock'`

- [ ] **Step 1: Write the failing test**

`tests/Feature/MockProviderTest.php`:

```php
<?php

use App\Enums\Subject;
use App\Models\Style;
use App\Services\ModelProviders\MockAssets;
use App\Services\ModelProviders\MockProvider;
use App\Services\ModelProviders\ModelProvider;
use App\Services\ModelProviders\PermanentProviderException;
use App\Services\ModelProviders\ProviderState;

beforeEach(function () {
    config(['models.mock.delay_seconds' => 10, 'models.mock.fail_rate' => 0]);
    $this->provider = new MockProvider;
    $this->style = Style::factory()->make(['subject' => Subject::Pet]);
});

it('is the bound provider by default', function () {
    expect(app(ModelProvider::class))->toBeInstanceOf(MockProvider::class);
});

it('moves from pending to running to succeeded over the delay', function () {
    $id = $this->provider->start('/tmp/photo.jpg', $this->style);

    expect($this->provider->status($id)->state)->toBe(ProviderState::Pending);

    $this->travel(5)->seconds();
    $running = $this->provider->status($id);
    expect($running->state)->toBe(ProviderState::Running)
        ->and($running->progress)->toBe(50);

    $this->travel(6)->seconds();
    $done = $this->provider->status($id);
    expect($done->state)->toBe(ProviderState::Succeeded)
        ->and($done->modelUrl)->toBe('mock://model/pet')
        ->and($done->thumbnailUrl)->toBe('mock://thumbnail/pet')
        ->and($done->progress)->toBe(100);
});

it('fails every job when the fail rate is 1', function () {
    config(['models.mock.fail_rate' => 1, 'models.mock.delay_seconds' => 0]);

    $result = $this->provider->status($this->provider->start('/tmp/photo.jpg', $this->style));

    expect($result->state)->toBe(ProviderState::Failed)->and($result->error)->not->toBeNull();
});

it('reports unknown jobs as failed', function () {
    expect($this->provider->status('nope')->state)->toBe(ProviderState::Failed);
});

it('downloads a valid GLB per subject', function () {
    $bytes = $this->provider->download('mock://model/person');

    expect(substr($bytes, 0, 4))->toBe('glTF')
        ->and(unpack('V', substr($bytes, 4, 4))[1])->toBe(2)
        ->and(unpack('V', substr($bytes, 8, 4))[1])->toBe(strlen($bytes));
});

it('downloads a PNG thumbnail', function () {
    $bytes = $this->provider->download('mock://thumbnail/object');

    expect(substr($bytes, 0, 8))->toBe("\x89PNG\r\n\x1a\n");
});

it('refuses to download unknown urls', function () {
    expect(fn () => $this->provider->download('https://evil.test/x.glb'))
        ->toThrow(PermanentProviderException::class);
});

it('builds distinct assets per subject', function () {
    expect(MockAssets::glb(Subject::Person))->not->toBe(MockAssets::glb(Subject::Pet));
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/MockProviderTest.php`
Expected: FAIL (classes not found).

- [ ] **Step 3: Config**

`config/models.php`:

```php
<?php

return [
    // Which ModelProvider implementation to use. Only "mock" exists today.
    'provider' => env('MODEL_PROVIDER', 'mock'),

    // A creation still unfinished after this long is failed and refunded.
    'timeout_seconds' => 600,

    // How long the job waits between provider status checks.
    'poll_seconds' => 3,

    'mock' => [
        'delay_seconds' => (int) env('MODEL_MOCK_DELAY_SECONDS', 6),
        'fail_rate' => (float) env('MODEL_MOCK_FAIL_RATE', 0),
    ],
];
```

- [ ] **Step 4: Interface, value objects, exceptions**

`app/Services/ModelProviders/ModelProvider.php`:

```php
<?php

namespace App\Services\ModelProviders;

use App\Models\Style;

interface ModelProvider
{
    /**
     * Submit a photo (absolute local path) for generation; returns the provider's job id.
     *
     * @throws TransientProviderException when retrying later may succeed
     * @throws PermanentProviderException when the input can never succeed
     */
    public function start(string $imagePath, Style $style): string;

    /**
     * Check a job. A job that is merely not ready yet is Pending/Running, never an exception.
     *
     * @throws TransientProviderException
     */
    public function status(string $providerJobId): ProviderResult;

    /**
     * Fetch the bytes behind a result URL. Provider URLs expire, so the caller stores the bytes.
     *
     * @throws TransientProviderException
     * @throws PermanentProviderException
     */
    public function download(string $url): string;
}
```

`app/Services/ModelProviders/ProviderState.php`:

```php
<?php

namespace App\Services\ModelProviders;

enum ProviderState
{
    case Pending;
    case Running;
    case Succeeded;
    case Failed;
}
```

`app/Services/ModelProviders/ProviderResult.php`:

```php
<?php

namespace App\Services\ModelProviders;

final class ProviderResult
{
    public function __construct(
        public readonly ProviderState $state,
        public readonly ?string $modelUrl = null,
        public readonly ?string $thumbnailUrl = null,
        public readonly ?string $error = null,
        public readonly ?int $progress = null,
    ) {}
}
```

`app/Services/ModelProviders/ProviderException.php`:

```php
<?php

namespace App\Services\ModelProviders;

use RuntimeException;

abstract class ProviderException extends RuntimeException {}
```

`app/Services/ModelProviders/TransientProviderException.php`:

```php
<?php

namespace App\Services\ModelProviders;

/** Network errors, 5xx, rate limits: retrying later may work. */
class TransientProviderException extends ProviderException {}
```

`app/Services/ModelProviders/PermanentProviderException.php`:

```php
<?php

namespace App\Services\ModelProviders;

/** Invalid image, content rejected, unknown asset: retrying cannot help. */
class PermanentProviderException extends ProviderException {}
```

- [ ] **Step 5: MockAssets (generates a valid GLB cube and a PNG, no binary files in the repo)**

`app/Services/ModelProviders/MockAssets.php`:

```php
<?php

namespace App\Services\ModelProviders;

use App\Enums\Subject;

final class MockAssets
{
    /** @return array{0:int,1:int,2:int} */
    private static function color(Subject $subject): array
    {
        return match ($subject) {
            Subject::Person => [233, 150, 122],
            Subject::Pet => [135, 170, 222],
            Subject::Object => [152, 200, 160],
        };
    }

    /** A minimal valid binary glTF (GLB): one coloured unit cube. */
    public static function glb(Subject $subject): string
    {
        [$r, $g, $b] = self::color($subject);

        // Vertex i has x = bit0, y = bit1, z = bit2 (0 → -0.5, 1 → +0.5).
        $positions = [];
        for ($i = 0; $i < 8; $i++) {
            $positions[] = ($i & 1) ? 0.5 : -0.5;
            $positions[] = ($i & 2) ? 0.5 : -0.5;
            $positions[] = ($i & 4) ? 0.5 : -0.5;
        }

        // Two counter-clockwise triangles per face, seen from outside.
        $indices = [
            4, 5, 7, 4, 7, 6,   // +z
            1, 0, 2, 1, 2, 3,   // -z
            1, 3, 7, 1, 7, 5,   // +x
            0, 4, 6, 0, 6, 2,   // -x
            2, 6, 7, 2, 7, 3,   // +y
            0, 1, 5, 0, 5, 4,   // -y
        ];

        $bin = pack('g*', ...$positions).pack('v*', ...$indices); // 96 + 72 bytes

        $gltf = [
            'asset' => ['version' => '2.0', 'generator' => '3d-maker mock'],
            'scene' => 0,
            'scenes' => [['nodes' => [0]]],
            'nodes' => [['mesh' => 0]],
            'meshes' => [['primitives' => [['attributes' => ['POSITION' => 0], 'indices' => 1, 'material' => 0]]]],
            'materials' => [['pbrMetallicRoughness' => [
                'baseColorFactor' => [$r / 255, $g / 255, $b / 255, 1.0],
                'metallicFactor' => 0.1,
                'roughnessFactor' => 0.6,
            ]]],
            'accessors' => [
                ['bufferView' => 0, 'componentType' => 5126, 'count' => 8, 'type' => 'VEC3', 'min' => [-0.5, -0.5, -0.5], 'max' => [0.5, 0.5, 0.5]],
                ['bufferView' => 1, 'componentType' => 5123, 'count' => 36, 'type' => 'SCALAR'],
            ],
            'bufferViews' => [
                ['buffer' => 0, 'byteOffset' => 0, 'byteLength' => 96, 'target' => 34962],
                ['buffer' => 0, 'byteOffset' => 96, 'byteLength' => 72, 'target' => 34963],
            ],
            'buffers' => [['byteLength' => strlen($bin)]],
        ];

        $json = json_encode($gltf, JSON_UNESCAPED_SLASHES);
        $json .= str_repeat(' ', (4 - strlen($json) % 4) % 4);

        $total = 12 + 8 + strlen($json) + 8 + strlen($bin);

        return pack('V3', 0x46546C67, 2, $total)
            .pack('V2', strlen($json), 0x4E4F534A).$json
            .pack('V2', strlen($bin), 0x004E4942).$bin;
    }

    /** A flat-colour 256x256 PNG. */
    public static function thumbnail(Subject $subject): string
    {
        [$r, $g, $b] = self::color($subject);

        $image = imagecreatetruecolor(256, 256);
        imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b));

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
```

- [ ] **Step 6: MockProvider**

`app/Services/ModelProviders/MockProvider.php`:

```php
<?php

namespace App\Services\ModelProviders;

use App\Enums\Subject;
use App\Models\Style;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class MockProvider implements ModelProvider
{
    public function start(string $imagePath, Style $style): string
    {
        $id = 'mock_'.Str::uuid();
        $rate = (float) config('models.mock.fail_rate');
        $fails = $rate > 0 && random_int(1, 1_000_000) <= $rate * 1_000_000;

        Cache::put($this->key($id), [
            'started_at' => now()->getTimestamp(),
            'subject' => $style->subject->value,
            'fails' => $fails,
        ], now()->addHour());

        return $id;
    }

    public function status(string $providerJobId): ProviderResult
    {
        $job = Cache::get($this->key($providerJobId));

        if (! $job) {
            return new ProviderResult(ProviderState::Failed, error: 'Unknown mock job.');
        }

        $delay = max(0, (int) config('models.mock.delay_seconds'));
        $elapsed = now()->getTimestamp() - $job['started_at'];

        if ($elapsed < $delay) {
            return new ProviderResult(
                $elapsed <= 0 ? ProviderState::Pending : ProviderState::Running,
                progress: (int) floor($elapsed / $delay * 100),
            );
        }

        if ($job['fails']) {
            return new ProviderResult(ProviderState::Failed, error: 'Mock provider simulated a failure.');
        }

        return new ProviderResult(
            ProviderState::Succeeded,
            modelUrl: "mock://model/{$job['subject']}",
            thumbnailUrl: "mock://thumbnail/{$job['subject']}",
            progress: 100,
        );
    }

    public function download(string $url): string
    {
        if (! preg_match('#^mock://(model|thumbnail)/(person|pet|object)$#', $url, $m)) {
            throw new PermanentProviderException("Unsupported mock asset URL [{$url}].");
        }

        $subject = Subject::from($m[2]);

        return $m[1] === 'model' ? MockAssets::glb($subject) : MockAssets::thumbnail($subject);
    }

    private function key(string $id): string
    {
        return "mock-provider:{$id}";
    }
}
```

- [ ] **Step 7: Service provider and registration**

`app/Providers/CreatorServiceProvider.php`:

```php
<?php

namespace App\Providers;

use App\Services\ModelProviders\MockProvider;
use App\Services\ModelProviders\ModelProvider;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class CreatorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ModelProvider::class, fn () => match (config('models.provider')) {
            'mock' => new MockProvider,
            default => throw new InvalidArgumentException('Unknown model provider ['.config('models.provider').'].'),
        });
    }
}
```

Read `bootstrap/providers.php` and add `App\Providers\CreatorServiceProvider::class,` to the returned array (keep existing entries).

- [ ] **Step 8: Run tests**

Run: `php artisan test tests/Feature/MockProviderTest.php`
Expected: PASS (8 tests). Rate `0` never fails and rate `1` always fails.

- [ ] **Step 9: Commit**

```bash
git add app config bootstrap tests
git commit -m "feat: add ModelProvider interface and mock provider

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: ImageSanitizer

**Files:**
- Create: `app/Exceptions/InvalidImageException.php`, `app/Services/ImageSanitizer.php`
- Test: `tests/Feature/ImageSanitizerTest.php`

**Interfaces:**
- Produces: `ImageSanitizer::sanitize(string $path): string` returns re-encoded JPEG bytes (EXIF stripped after applying orientation, flattened onto white, longest edge ≤ 2048); throws `InvalidImageException` for anything GD cannot decode.

- [ ] **Step 1: Write the failing test**

`tests/Feature/ImageSanitizerTest.php`:

```php
<?php

use App\Exceptions\InvalidImageException;
use App\Services\ImageSanitizer;

function tempImage(int $w, int $h, string $format = 'png'): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 200, 50, 50));
    $path = tempnam(sys_get_temp_dir(), 'img');
    $format === 'png' ? imagepng($img, $path) : imagejpeg($img, $path);

    return $path;
}

it('re-encodes any supported image as jpeg', function () {
    $path = tempImage(800, 600, 'png');

    $bytes = (new ImageSanitizer)->sanitize($path);

    expect(substr($bytes, 0, 2))->toBe("\xFF\xD8");
    [$w, $h] = getimagesizefromstring($bytes);
    expect([$w, $h])->toBe([800, 600]);
});

it('downscales images whose longest edge exceeds 2048', function () {
    $path = tempImage(4096, 2048);

    [$w, $h] = getimagesizefromstring((new ImageSanitizer)->sanitize($path));

    expect([$w, $h])->toBe([2048, 1024]);
});

it('rejects files that are not images', function () {
    $path = tempnam(sys_get_temp_dir(), 'txt');
    file_put_contents($path, '<?php echo "hi";');

    expect(fn () => (new ImageSanitizer)->sanitize($path))->toThrow(InvalidImageException::class);
});

it('rejects missing files', function () {
    expect(fn () => (new ImageSanitizer)->sanitize('/no/such/file.jpg'))->toThrow(InvalidImageException::class);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/ImageSanitizerTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement**

`app/Exceptions/InvalidImageException.php`:

```php
<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidImageException extends RuntimeException {}
```

`app/Services/ImageSanitizer.php`:

```php
<?php

namespace App\Services;

use App\Exceptions\InvalidImageException;
use GdImage;

class ImageSanitizer
{
    public const MAX_EDGE = 2048;

    /**
     * Decode and re-encode an upload as a clean JPEG: metadata stripped, orientation
     * applied, transparency flattened onto white, longest edge capped.
     *
     * @throws InvalidImageException
     */
    public function sanitize(string $path): string
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            throw new InvalidImageException('The image could not be read.');
        }

        $source = @imagecreatefromstring($contents);
        if (! $source instanceof GdImage) {
            throw new InvalidImageException('The file is not a valid image.');
        }

        $source = $this->applyOrientation($source, $contents);
        $canvas = $this->flattenAndScale($source);

        ob_start();
        imagejpeg($canvas, null, 90);

        return (string) ob_get_clean();
    }

    private function applyOrientation(GdImage $image, string $contents): GdImage
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($contents, "\xFF\xD8")) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($contents));
        $angle = match ($exif['Orientation'] ?? 1) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        return imagerotate($image, $angle, 0) ?: $image;
    }

    private function flattenAndScale(GdImage $source): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, self::MAX_EDGE / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $canvas;
    }
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Feature/ImageSanitizerTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app tests
git commit -m "feat: add image sanitizer that re-encodes uploads as clean jpeg

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: GenerateCreation job

**Files:**
- Create: `app/Jobs/GenerateCreation.php`
- Test: `tests/Feature/GenerateCreationTest.php`

**Interfaces:**
- Consumes: `ModelProvider`, `ProviderResult`, `ProviderState`, `TransientProviderException`, `PermanentProviderException` (Task 4); `CreditService::refund` (Task 3); `Creation`, `CreationStatus` (Task 2); config `models.timeout_seconds`, `models.poll_seconds`.
- Produces: `new GenerateCreation(int $creationId)`. On success the creation is `succeeded` with `model_path = creations/{id}/model.glb` and `thumbnail_path = creations/{id}/thumbnail.png` (on disk `local`); on any failure it is `failed` with `error` set and the cost refunded once. Finished creations make the job a no-op.

Behavior (from spec): hard timeout → failed + refund; polling via `release($delay)`, never sleeping; transient errors release and retry until the timeout; permanent errors fail immediately; provider output is downloaded into our storage; idempotent.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/GenerateCreationTest.php`:

```php
<?php

use App\Enums\CreationStatus;
use App\Enums\LedgerReason;
use App\Jobs\GenerateCreation;
use App\Models\Creation;
use App\Models\CreditLedgerEntry;
use App\Models\Style;
use App\Models\User;
use App\Services\Credits\CreditService;
use App\Services\ModelProviders\ModelProvider;
use App\Services\ModelProviders\PermanentProviderException;
use App\Services\ModelProviders\ProviderResult;
use App\Services\ModelProviders\ProviderState;
use App\Services\ModelProviders\TransientProviderException;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config(['models.mock.delay_seconds' => 0, 'models.mock.fail_rate' => 0, 'models.poll_seconds' => 3]);

    $this->credits = app(CreditService::class);
    $this->user = User::factory()->create();
    $this->credits->grant($this->user, 20, LedgerReason::Signup);

    $this->makeCreation = function (array $attrs = []) {
        $creation = Creation::factory()->create(['user_id' => $this->user->id, 'style_id' => Style::factory()] + $attrs);
        $this->credits->spend($this->user, 5, $creation);

        return $creation;
    };

    $this->runJob = function (Creation $creation) {
        $job = (new GenerateCreation($creation->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        return $job;
    };
});

it('stores the model and thumbnail and keeps the charge on success', function () {
    $creation = ($this->makeCreation)();

    ($this->runJob)($creation);

    $creation->refresh();
    expect($creation->status)->toBe(CreationStatus::Succeeded)
        ->and($creation->model_path)->toBe("creations/{$creation->id}/model.glb")
        ->and($creation->thumbnail_path)->toBe("creations/{$creation->id}/thumbnail.png")
        ->and($creation->progress)->toBe(100);
    Storage::disk('local')->assertExists($creation->model_path);
    Storage::disk('local')->assertExists($creation->thumbnail_path);
    expect($this->credits->balance($this->user))->toBe(15);
});

it('fails and refunds exactly once when the provider fails', function () {
    config(['models.mock.fail_rate' => 1]);
    $creation = ($this->makeCreation)();

    ($this->runJob)($creation);
    ($this->runJob)($creation); // re-running a finished creation is a no-op

    $creation->refresh();
    expect($creation->status)->toBe(CreationStatus::Failed)
        ->and($creation->error)->not->toBeNull()
        ->and($this->credits->balance($this->user))->toBe(20)
        ->and(CreditLedgerEntry::where('reason', LedgerReason::Refund)->count())->toBe(1);
});

it('releases the job while the provider is still working', function () {
    config(['models.mock.delay_seconds' => 30]);
    $creation = ($this->makeCreation)();

    $job = ($this->runJob)($creation);

    $job->assertReleased(delay: 3);
    $creation->refresh();
    expect($creation->status)->toBe(CreationStatus::Processing)
        ->and($creation->provider_job_id)->not->toBeNull()
        ->and($this->credits->balance($this->user))->toBe(15);
});

it('does not start a second provider job when it resumes', function () {
    config(['models.mock.delay_seconds' => 30]);
    $creation = ($this->makeCreation)();

    ($this->runJob)($creation);
    $firstJobId = $creation->refresh()->provider_job_id;
    ($this->runJob)($creation);

    expect($creation->refresh()->provider_job_id)->toBe($firstJobId);
});

it('times out, fails, and refunds', function () {
    $creation = ($this->makeCreation)();
    $creation->forceFill(['created_at' => now()->subSeconds(601)])->save();

    ($this->runJob)($creation);

    $creation->refresh();
    expect($creation->status)->toBe(CreationStatus::Failed)
        ->and($creation->error)->toContain('timed out')
        ->and($this->credits->balance($this->user))->toBe(20);
});

it('retries later on transient provider errors without failing', function () {
    $this->app->bind(ModelProvider::class, fn () => new class implements ModelProvider
    {
        public function start(string $imagePath, Style $style): string
        {
            throw new TransientProviderException('503');
        }

        public function status(string $providerJobId): ProviderResult
        {
            throw new TransientProviderException('503');
        }

        public function download(string $url): string
        {
            throw new TransientProviderException('503');
        }
    });
    $creation = ($this->makeCreation)();

    $job = ($this->runJob)($creation);

    $job->assertReleased(delay: 10);
    expect($creation->refresh()->status)->toBe(CreationStatus::Queued)
        ->and($this->credits->balance($this->user))->toBe(15);
});

it('fails immediately and refunds on permanent provider errors', function () {
    $this->app->bind(ModelProvider::class, fn () => new class implements ModelProvider
    {
        public function start(string $imagePath, Style $style): string
        {
            throw new PermanentProviderException('Image rejected.');
        }

        public function status(string $providerJobId): ProviderResult
        {
            return new ProviderResult(ProviderState::Failed);
        }

        public function download(string $url): string
        {
            return '';
        }
    });
    $creation = ($this->makeCreation)();

    ($this->runJob)($creation);

    $creation->refresh();
    expect($creation->status)->toBe(CreationStatus::Failed)
        ->and($creation->error)->toBe('Image rejected.')
        ->and($this->credits->balance($this->user))->toBe(20);
});

it('marks the creation failed and refunds if the job itself blows up', function () {
    $creation = ($this->makeCreation)();

    (new GenerateCreation($creation->id))->failed(new RuntimeException('boom'));

    expect($creation->refresh()->status)->toBe(CreationStatus::Failed)
        ->and($this->credits->balance($this->user))->toBe(20);
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test tests/Feature/GenerateCreationTest.php`
Expected: FAIL (class `App\Jobs\GenerateCreation` not found).

- [ ] **Step 3: Implement the job**

`app/Jobs/GenerateCreation.php`:

```php
<?php

namespace App\Jobs;

use App\Enums\CreationStatus;
use App\Models\Creation;
use App\Services\Credits\CreditService;
use App\Services\ModelProviders\ModelProvider;
use App\Services\ModelProviders\PermanentProviderException;
use App\Services\ModelProviders\ProviderState;
use App\Services\ModelProviders\TransientProviderException;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateCreation implements ShouldQueue
{
    use Queueable;

    /** Seconds to wait before retrying after a transient provider error. */
    private const TRANSIENT_RETRY_SECONDS = 10;

    /** Give up after this many unexpected (non-provider) exceptions. */
    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [5, 15, 30];

    public function __construct(public int $creationId) {}

    /**
     * Polling re-releases the job, which counts as an attempt, so the retry window
     * (not a fixed attempt count) bounds the job. Our own deadline check in handle()
     * fires first; this is the safety net.
     */
    public function retryUntil(): CarbonInterface
    {
        return now()->addSeconds((int) config('models.timeout_seconds') + 120);
    }

    public function handle(ModelProvider $provider, CreditService $credits): void
    {
        $creation = Creation::with('style')->find($this->creationId);

        if (! $creation || $creation->status->isFinished()) {
            return;
        }

        if ($creation->created_at->addSeconds((int) config('models.timeout_seconds'))->isPast()) {
            $this->markFailed($creation, 'Generation timed out.', $credits);

            return;
        }

        try {
            if (! $creation->provider_job_id) {
                $creation->provider_job_id = $provider->start(
                    Storage::disk('local')->path($creation->source_image_path),
                    $creation->style,
                );
                $creation->status = CreationStatus::Processing;
                $creation->save();
            }

            $result = $provider->status($creation->provider_job_id);

            match ($result->state) {
                ProviderState::Pending, ProviderState::Running => $this->stillWorking($creation, $result->progress),
                ProviderState::Failed => $this->markFailed($creation, $result->error ?? 'The model could not be generated.', $credits),
                ProviderState::Succeeded => $this->succeed($creation, $provider, $result->modelUrl, $result->thumbnailUrl),
            };
        } catch (TransientProviderException) {
            $this->release(self::TRANSIENT_RETRY_SECONDS);
        } catch (PermanentProviderException $e) {
            $this->markFailed($creation, $e->getMessage(), $credits);
        }
    }

    /** Called by the queue when the job exhausts its retries or throws unexpectedly. */
    public function failed(?Throwable $exception): void
    {
        $creation = Creation::find($this->creationId);

        if ($creation && ! $creation->status->isFinished()) {
            $this->markFailed($creation, 'Generation failed unexpectedly.', app(CreditService::class));
        }
    }

    private function stillWorking(Creation $creation, ?int $progress): void
    {
        $creation->forceFill(['status' => CreationStatus::Processing, 'progress' => $progress])->save();
        $this->release((int) config('models.poll_seconds'));
    }

    private function succeed(Creation $creation, ModelProvider $provider, ?string $modelUrl, ?string $thumbnailUrl): void
    {
        if (! $modelUrl) {
            throw new PermanentProviderException('The provider finished without a model.');
        }

        $disk = Storage::disk('local');
        $modelPath = "creations/{$creation->id}/model.glb";
        $thumbPath = null;

        $disk->put($modelPath, $provider->download($modelUrl));

        if ($thumbnailUrl) {
            $thumbPath = "creations/{$creation->id}/thumbnail.png";
            $disk->put($thumbPath, $provider->download($thumbnailUrl));
        }

        $creation->forceFill([
            'status' => CreationStatus::Succeeded,
            'model_path' => $modelPath,
            'thumbnail_path' => $thumbPath,
            'progress' => 100,
            'error' => null,
        ])->save();
    }

    private function markFailed(Creation $creation, string $message, CreditService $credits): void
    {
        $creation->forceFill(['status' => CreationStatus::Failed, 'error' => $message])->save();
        $credits->refund($creation);
    }
}
```

Note: the helper is named `markFailed` on purpose. `Queueable` pulls in `InteractsWithQueue`, which already defines a public `fail()`; reusing that name would be a signature conflict.

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Feature/GenerateCreationTest.php`
Expected: PASS (8 tests). If a failure mentions `fail()` signature, apply the rename described above.

- [ ] **Step 5: Commit**

```bash
git add app tests
git commit -m "feat: add GenerateCreation job with polling, timeout, and refunds

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Creation submission, controllers, policy, routes

**Files:**
- Create: `app/Services/CreationService.php`, `app/Http/Requests/StoreCreationRequest.php`, `app/Http/Resources/CreationResource.php`, `app/Http/Controllers/CreationController.php`, `app/Http/Controllers/CreationFileController.php`, `app/Http/Controllers/SampleController.php`, `app/Policies/CreationPolicy.php`
- Modify: `app/Providers/CreatorServiceProvider.php` (rate limiter), `routes/web.php`
- Test: `tests/Feature/CreationFlowTest.php`, `tests/Feature/CreationAccessTest.php`

**Interfaces:**
- Consumes: `CreditService` (Task 3), `ImageSanitizer` (Task 5), `GenerateCreation` (Task 6), `MockAssets` (Task 4), models (Task 2).
- Produces:
  - `CreationService::submit(User $user, UploadedFile $photo, Style $style): Creation` (throws `InsufficientCreditsException`, `InvalidImageException`; deletes the stored upload if the transaction fails)
  - Routes (all behind `auth` except the sample): `GET /create` (`creations.create`), `POST /creations` (`creations.store`, `throttle:generate`), `GET /creations` (`creations.index`), `GET /creations/{creation}` (`creations.show`), `DELETE /creations/{creation}` (`creations.destroy`), `GET /creations/{creation}/files/{type}` (`creations.files`, type ∈ source|model|thumbnail, `?download=1` for the model), public `GET /samples/demo.glb`
  - Inertia props: `Create` → `{ styles: [{id, subject, name, look, credit_cost}], balance: int }`; `creations/Index` → `{ creations: CreationPayload[] }`; `creations/Show` → `{ creation: CreationPayload }`
  - `CreationPayload` = `{ id, status, error, progress, cost_credits, created_at, style: {name, subject, look}, urls: {source, model|null, thumbnail|null, download|null} }`
  - Validation errors land on `photo` and `style_id`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/CreationFlowTest.php`:

```php
<?php

use App\Enums\CreationStatus;
use App\Enums\LedgerReason;
use App\Jobs\GenerateCreation;
use App\Models\Creation;
use App\Models\Style;
use App\Models\User;
use App\Services\Credits\CreditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    app(CreditService::class)->grant($this->user, 20, LedgerReason::Signup);
    $this->style = Style::factory()->create(['credit_cost' => 5]);
});

it('requires authentication', function () {
    $this->get('/create')->assertRedirect('/login');
    $this->post('/creations')->assertRedirect('/login');
});

it('lists active styles and the balance on the create page', function () {
    Style::factory()->create(['active' => false]);

    $this->actingAs($this->user)->get('/create')->assertInertia(fn (Assert $page) => $page
        ->component('Create')
        ->has('styles', 1)
        ->where('styles.0.id', $this->style->id)
        ->where('balance', 20));
});

it('creates a queued creation, charges credits, and dispatches the job', function () {
    Queue::fake();

    $response = $this->actingAs($this->user)->post('/creations', [
        'photo' => UploadedFile::fake()->image('me.jpg', 800, 800),
        'style_id' => $this->style->id,
    ]);

    $creation = Creation::firstOrFail();
    $response->assertRedirect("/creations/{$creation->id}");
    expect($creation->status)->toBe(CreationStatus::Queued)
        ->and($creation->user_id)->toBe($this->user->id)
        ->and($creation->cost_credits)->toBe(5)
        ->and(app(CreditService::class)->balance($this->user))->toBe(15);
    Storage::disk('local')->assertExists($creation->source_image_path);
    Queue::assertPushed(GenerateCreation::class, fn ($job) => $job->creationId === $creation->id);
});

it('runs end to end with the mock provider', function () {
    config(['queue.default' => 'sync', 'models.mock.delay_seconds' => 0, 'models.mock.fail_rate' => 0]);

    $this->actingAs($this->user)->post('/creations', [
        'photo' => UploadedFile::fake()->image('me.jpg', 800, 800),
        'style_id' => $this->style->id,
    ]);

    $creation = Creation::firstOrFail();
    expect($creation->status)->toBe(CreationStatus::Succeeded);
    Storage::disk('local')->assertExists($creation->model_path);
});

it('rejects a submission the user cannot afford', function () {
    Queue::fake();
    $expensive = Style::factory()->create(['credit_cost' => 50]);

    $this->actingAs($this->user)->post('/creations', [
        'photo' => UploadedFile::fake()->image('me.jpg', 800, 800),
        'style_id' => $expensive->id,
    ])->assertSessionHasErrors('style_id');

    expect(Creation::count())->toBe(0)
        ->and(app(CreditService::class)->balance($this->user))->toBe(20);
    Queue::assertNothingPushed();
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('validates the photo and style', function (array $payload, string $field) {
    Queue::fake();

    $this->actingAs($this->user)->post('/creations', $payload + [
        'photo' => UploadedFile::fake()->image('me.jpg', 800, 800),
        'style_id' => $this->style->id,
    ])->assertSessionHasErrors($field);

    expect(Creation::count())->toBe(0);
})->with([
    'not an image' => [['photo' => UploadedFile::fake()->create('x.txt', 10, 'text/plain')], 'photo'],
    'too small' => [['photo' => UploadedFile::fake()->image('s.jpg', 100, 100)], 'photo'],
    'too large' => [['photo' => UploadedFile::fake()->image('b.jpg', 800, 800)->size(11000)], 'photo'],
    'missing style' => [['style_id' => null], 'style_id'],
    'unknown style' => [['style_id' => 9999], 'style_id'],
]);

it('rejects inactive styles', function () {
    Queue::fake();
    $inactive = Style::factory()->create(['active' => false]);

    $this->actingAs($this->user)->post('/creations', [
        'photo' => UploadedFile::fake()->image('me.jpg', 800, 800),
        'style_id' => $inactive->id,
    ])->assertSessionHasErrors('style_id');
});
```

`tests/Feature/CreationAccessTest.php`:

```php
<?php

use App\Enums\CreationStatus;
use App\Models\Creation;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    $this->owner = User::factory()->create();
    $this->other = User::factory()->create();

    Storage::disk('local')->put('uploads/a.jpg', 'jpg-bytes');
    Storage::disk('local')->put('creations/1/model.glb', 'glb-bytes');

    $this->creation = Creation::factory()->create([
        'user_id' => $this->owner->id,
        'status' => CreationStatus::Succeeded,
        'source_image_path' => 'uploads/a.jpg',
        'model_path' => 'creations/1/model.glb',
        'thumbnail_path' => null,
    ]);
});

it('shows a creation to its owner with file urls', function () {
    $this->actingAs($this->owner)->get("/creations/{$this->creation->id}")->assertInertia(fn (Assert $page) => $page
        ->component('creations/Show')
        ->where('creation.id', $this->creation->id)
        ->where('creation.status', 'succeeded')
        ->where('creation.urls.model', "/creations/{$this->creation->id}/files/model")
        ->where('creation.urls.download', "/creations/{$this->creation->id}/files/model?download=1")
        ->where('creation.urls.thumbnail', null));
});

it('forbids other users from every creation route', function () {
    $id = $this->creation->id;

    $this->actingAs($this->other)->get("/creations/{$id}")->assertForbidden();
    $this->actingAs($this->other)->get("/creations/{$id}/files/source")->assertForbidden();
    $this->actingAs($this->other)->get("/creations/{$id}/files/model")->assertForbidden();
    $this->actingAs($this->other)->delete("/creations/{$id}")->assertForbidden();
});

it('serves files to the owner and supports download', function () {
    $id = $this->creation->id;

    $this->actingAs($this->owner)->get("/creations/{$id}/files/source")->assertOk();
    $this->actingAs($this->owner)->get("/creations/{$id}/files/model")->assertOk();
    $this->actingAs($this->owner)->get("/creations/{$id}/files/model?download=1")
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename=creation-'.$id.'.glb');
    $this->actingAs($this->owner)->get("/creations/{$id}/files/thumbnail")->assertNotFound();
});

it('lists only the current users creations', function () {
    Creation::factory()->create(['user_id' => $this->other->id]);

    $this->actingAs($this->owner)->get('/creations')->assertInertia(fn (Assert $page) => $page
        ->component('creations/Index')
        ->has('creations', 1));
});

it('deletes a finished creation and its files', function () {
    $this->actingAs($this->owner)->delete("/creations/{$this->creation->id}")->assertRedirect('/creations');

    expect(Creation::count())->toBe(0);
    Storage::disk('local')->assertMissing('uploads/a.jpg');
    Storage::disk('local')->assertMissing('creations/1/model.glb');
});

it('refuses to delete a creation that is still running', function () {
    $running = Creation::factory()->create(['user_id' => $this->owner->id, 'status' => CreationStatus::Processing]);

    $this->actingAs($this->owner)->delete("/creations/{$running->id}")->assertForbidden();
    expect(Creation::count())->toBe(2);
});

it('serves the public demo model', function () {
    $this->get('/samples/demo.glb')->assertOk();
    expect(substr($this->get('/samples/demo.glb')->getContent(), 0, 4))->toBe('glTF');
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test tests/Feature/CreationFlowTest.php tests/Feature/CreationAccessTest.php`
Expected: FAIL (404s / missing classes).

- [ ] **Step 3: CreationService**

`app/Services/CreationService.php`:

```php
<?php

namespace App\Services;

use App\Enums\CreationStatus;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\InvalidImageException;
use App\Jobs\GenerateCreation;
use App\Models\Creation;
use App\Models\Style;
use App\Models\User;
use App\Services\Credits\CreditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class CreationService
{
    public function __construct(
        private ImageSanitizer $sanitizer,
        private CreditService $credits,
    ) {}

    /**
     * Store a cleaned copy of the photo, charge the user, and queue generation.
     *
     * @throws InvalidImageException
     * @throws InsufficientCreditsException
     */
    public function submit(User $user, UploadedFile $photo, Style $style): Creation
    {
        $jpeg = $this->sanitizer->sanitize($photo->getRealPath());
        $path = 'uploads/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, $jpeg);

        try {
            $creation = DB::transaction(function () use ($user, $style, $path) {
                $creation = Creation::create([
                    'user_id' => $user->id,
                    'style_id' => $style->id,
                    'source_image_path' => $path,
                    'status' => CreationStatus::Queued,
                    'cost_credits' => $style->credit_cost,
                ]);

                $this->credits->spend($user, $style->credit_cost, $creation);

                return $creation;
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);

            throw $e;
        }

        GenerateCreation::dispatch($creation->id);

        return $creation;
    }
}
```

- [ ] **Step 4: Form request, resource, policy**

`app/Http/Requests/StoreCreationRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCreationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240', 'dimensions:min_width=512,min_height=512'],
            'style_id' => ['required', 'integer', Rule::exists('styles', 'id')->where('active', true)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'photo.max' => 'The photo must be 10 MB or smaller.',
            'photo.dimensions' => 'The photo must be at least 512 x 512 pixels.',
            'photo.mimes' => 'Use a JPG, PNG, or WebP photo.',
            'style_id.exists' => 'Choose one of the available styles.',
        ];
    }
}
```

`app/Http/Resources/CreationResource.php`:

```php
<?php

namespace App\Http\Resources;

use App\Models\Creation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Creation */
class CreationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $file = fn (string $type, array $query = []) => route(
            'creations.files',
            ['creation' => $this->id, 'type' => $type] + $query,
            absolute: false,
        );

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'error' => $this->error,
            'progress' => $this->progress,
            'cost_credits' => $this->cost_credits,
            'created_at' => $this->created_at->toIso8601String(),
            'style' => [
                'name' => $this->style->name,
                'subject' => $this->style->subject->value,
                'look' => $this->style->look,
            ],
            'urls' => [
                'source' => $file('source'),
                'model' => $this->model_path ? $file('model') : null,
                'thumbnail' => $this->thumbnail_path ? $file('thumbnail') : null,
                'download' => $this->model_path ? $file('model', ['download' => 1]) : null,
            ],
        ];
    }
}
```

`app/Policies/CreationPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Creation;
use App\Models\User;

class CreationPolicy
{
    public function view(User $user, Creation $creation): bool
    {
        return $creation->user_id === $user->id;
    }

    /** Only finished creations can be deleted, so a running job never loses its row. */
    public function delete(User $user, Creation $creation): bool
    {
        return $this->view($user, $creation) && $creation->status->isFinished();
    }
}
```

- [ ] **Step 5: Controllers**

`app/Http/Controllers/CreationController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\InvalidImageException;
use App\Http\Requests\StoreCreationRequest;
use App\Http\Resources\CreationResource;
use App\Models\Creation;
use App\Models\Style;
use App\Services\Credits\CreditService;
use App\Services\CreationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CreationController extends Controller
{
    public function create(Request $request, CreditService $credits): Response
    {
        return Inertia::render('Create', [
            'styles' => Style::active()->orderBy('subject')->orderBy('id')->get()
                ->map(fn (Style $s) => [
                    'id' => $s->id,
                    'subject' => $s->subject->value,
                    'name' => $s->name,
                    'look' => $s->look,
                    'credit_cost' => $s->credit_cost,
                ])->values(),
            'balance' => $credits->balance($request->user()),
        ]);
    }

    public function store(StoreCreationRequest $request, CreationService $creations): RedirectResponse
    {
        $style = Style::active()->findOrFail($request->integer('style_id'));

        try {
            $creation = $creations->submit($request->user(), $request->file('photo'), $style);
        } catch (InsufficientCreditsException $e) {
            throw ValidationException::withMessages([
                'style_id' => "Not enough credits: this style costs {$e->required} and you have {$e->balance}.",
            ]);
        } catch (InvalidImageException) {
            throw ValidationException::withMessages(['photo' => 'We could not read that image. Try another photo.']);
        }

        return redirect()->route('creations.show', $creation);
    }

    public function index(Request $request): Response
    {
        $creations = Creation::with('style')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(60)
            ->get();

        return Inertia::render('creations/Index', [
            'creations' => $creations->map(fn (Creation $c) => CreationResource::make($c)->resolve())->values(),
        ]);
    }

    public function show(Creation $creation): Response
    {
        Gate::authorize('view', $creation);

        return Inertia::render('creations/Show', [
            'creation' => CreationResource::make($creation->load('style'))->resolve(),
        ]);
    }

    public function destroy(Creation $creation): RedirectResponse
    {
        Gate::authorize('delete', $creation);

        $disk = Storage::disk('local');
        $disk->delete($creation->source_image_path);
        $disk->deleteDirectory("creations/{$creation->id}");
        $creation->delete();

        return redirect()->route('creations.index');
    }
}
```

`app/Http/Controllers/CreationFileController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Creation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CreationFileController extends Controller
{
    public function __invoke(Request $request, Creation $creation, string $type): StreamedResponse
    {
        Gate::authorize('view', $creation);

        $path = match ($type) {
            'source' => $creation->source_image_path,
            'model' => $creation->model_path,
            'thumbnail' => $creation->thumbnail_path,
        };

        $disk = Storage::disk('local');
        abort_if(! $path || ! $disk->exists($path), 404);

        if ($type === 'model' && $request->boolean('download')) {
            return $disk->download($path, "creation-{$creation->id}.glb");
        }

        return $disk->response($path, null, ['Cache-Control' => 'private, max-age=3600']);
    }
}
```

`app/Http/Controllers/SampleController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\Subject;
use App\Services\ModelProviders\MockAssets;
use Illuminate\Http\Response;

/** Public demo model for the landing page viewer. */
class SampleController extends Controller
{
    public function __invoke(): Response
    {
        return response(MockAssets::glb(Subject::Person), 200, [
            'Content-Type' => 'model/gltf-binary',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
```

- [ ] **Step 6: Rate limiter and routes**

In `app/Providers/CreatorServiceProvider.php` add the imports `use Illuminate\Cache\RateLimiting\Limit;`, `use Illuminate\Http\Request;`, `use Illuminate\Support\Facades\RateLimiter;` and this method:

```php
    public function boot(): void
    {
        RateLimiter::for('generate', fn (Request $request) => Limit::perMinute(10)
            ->by($request->user()?->id ?: $request->ip()));
    }
```

Read `routes/web.php`. Keep the kit's welcome route and its `require` lines (settings/auth). Remove the kit's dashboard route and add the block below, so the file's route section ends up as:

```php
use App\Http\Controllers\CreationController;
use App\Http\Controllers\CreationFileController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\SampleController;
use Illuminate\Support\Facades\Route;

// ...kept: Route::get('/', ...)->name('home');

Route::get('samples/demo.glb', SampleController::class)->name('samples.demo');

Route::middleware('auth')->group(function () {
    Route::redirect('dashboard', '/creations')->name('dashboard');

    Route::get('create', [CreationController::class, 'create'])->name('creations.create');
    Route::post('creations', [CreationController::class, 'store'])->middleware('throttle:generate')->name('creations.store');
    Route::get('creations', [CreationController::class, 'index'])->name('creations.index');
    Route::get('creations/{creation}', [CreationController::class, 'show'])->name('creations.show');
    Route::delete('creations/{creation}', [CreationController::class, 'destroy'])->name('creations.destroy');
    Route::get('creations/{creation}/files/{type}', CreationFileController::class)
        ->whereIn('type', ['source', 'model', 'thumbnail'])
        ->name('creations.files');

    Route::get('credits', [CreditController::class, 'index'])->name('credits.index');
    Route::post('credits/topup', [CreditController::class, 'topup'])->name('credits.topup');
});
```

(`CreditController` is created in Task 8; until then, `php artisan route:list` will fail on it. Create a stub now so this task is testable: `app/Http/Controllers/CreditController.php` with an empty `class CreditController extends Controller {}`; Task 8 fills it in.)

The kit's `tests/Feature/DashboardTest.php` expects an Inertia page; overwrite it with:

```php
<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('authenticated users are sent to their creations', function () {
    $this->actingAs(User::factory()->create())->get('/dashboard')->assertRedirect('/creations');
});
```

Also delete `resources/js/pages/Dashboard.vue` after Task 12 (it is still used as an import reference until then).

- [ ] **Step 7: Run tests**

Run: `php artisan test tests/Feature/CreationFlowTest.php tests/Feature/CreationAccessTest.php tests/Feature/DashboardTest.php`
Expected: PASS. Common failures: (a) `assertInertia ->component('Create')` fails because Inertia's testing config checks that the page file exists; the page files are created in Tasks 9–12, so if you see "Inertia page component file [Create] does not exist", set `config(['inertia.testing.ensure_pages_exist' => false])` in a `beforeEach` of these two test files **temporarily** and remove it in Task 12 Step 5, or just run these after Task 12; (b) the `too large` dataset can be flaky on `fake()->image()->size()`; if so use `UploadedFile::fake()->create('big.jpg', 11000, 'image/jpeg')`.

- [ ] **Step 8: Commit**

```bash
git add app routes tests
git commit -m "feat: add creation submission, file serving, policy, and routes

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Credits page backend

**Files:**
- Modify: `app/Http/Controllers/CreditController.php` (stub from Task 7)
- Test: `tests/Feature/CreditsPageTest.php`

**Interfaces:**
- Consumes: `CreditService`, `CreditLedgerEntry`, `LedgerReason`, config `credits.topup`, `credits.stub_topup`.
- Produces: `GET /credits` → Inertia `Credits` with `{ balance: int, ledger: [{id, delta, reason, created_at}], topup: { enabled: bool, amount: int } }`; `POST /credits/topup` grants `credits.topup` credits and redirects back, or 404 when `credits.stub_topup` is false.

- [ ] **Step 1: Write the failing test**

`tests/Feature/CreditsPageTest.php`:

```php
<?php

use App\Enums\LedgerReason;
use App\Models\User;
use App\Services\Credits\CreditService;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->credits = app(CreditService::class);
    $this->credits->grant($this->user, 20, LedgerReason::Signup);
});

it('shows the balance and ledger history, newest first', function () {
    $this->credits->grant($this->user, 5, LedgerReason::Topup);

    $this->actingAs($this->user)->get('/credits')->assertInertia(fn (Assert $page) => $page
        ->component('Credits')
        ->where('balance', 25)
        ->has('ledger', 2)
        ->where('ledger.0.reason', 'topup')
        ->where('ledger.0.delta', 5)
        ->where('topup.enabled', true));
});

it('only shows the current users ledger', function () {
    $this->credits->grant(User::factory()->create(), 99, LedgerReason::Topup);

    $this->actingAs($this->user)->get('/credits')->assertInertia(fn (Assert $page) => $page
        ->where('balance', 20)
        ->has('ledger', 1));
});

it('grants the stub top-up amount', function () {
    config(['credits.stub_topup' => true, 'credits.topup' => 25]);

    $this->actingAs($this->user)->post('/credits/topup')->assertRedirect();

    expect($this->credits->balance($this->user))->toBe(45);
});

it('disables the stub top-up when configured off', function () {
    config(['credits.stub_topup' => false]);

    $this->actingAs($this->user)->post('/credits/topup')->assertNotFound();

    expect($this->credits->balance($this->user))->toBe(20);
});

it('requires authentication', function () {
    $this->get('/credits')->assertRedirect('/login');
    $this->post('/credits/topup')->assertRedirect('/login');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/CreditsPageTest.php`
Expected: FAIL (controller methods missing).

- [ ] **Step 3: Implement**

`app/Http/Controllers/CreditController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\LedgerReason;
use App\Models\CreditLedgerEntry;
use App\Services\Credits\CreditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreditController extends Controller
{
    public function index(Request $request, CreditService $credits): Response
    {
        $user = $request->user();

        return Inertia::render('Credits', [
            'balance' => $credits->balance($user),
            'ledger' => CreditLedgerEntry::where('user_id', $user->id)
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (CreditLedgerEntry $e) => [
                    'id' => $e->id,
                    'delta' => $e->delta,
                    'reason' => $e->reason->value,
                    'created_at' => $e->created_at->toIso8601String(),
                ])->values(),
            'topup' => [
                'enabled' => (bool) config('credits.stub_topup'),
                'amount' => (int) config('credits.topup'),
            ],
        ]);
    }

    /** Stub: real payments arrive in a later sub-project. */
    public function topup(Request $request, CreditService $credits): RedirectResponse
    {
        abort_unless(config('credits.stub_topup'), 404);

        $credits->grant($request->user(), (int) config('credits.topup'), LedgerReason::Topup);

        return back();
    }
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Feature/CreditsPageTest.php`
Expected: PASS (5 tests; see the Task 7 note about Inertia page-existence checks if the component assertions complain).

- [ ] **Step 5: Commit**

```bash
git add app tests
git commit -m "feat: add credits page backend with stubbed top-up

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 9: ModelViewer component

**Files:**
- Create: `resources/js/components/ModelViewer.vue`
- Modify: `package.json` (via npm)

**Interfaces:**
- Produces: `<ModelViewer src="/creations/1/files/model" :auto-rotate="true" />`. Props: `src: string`, `autoRotate?: boolean` (default `true`). Self-contained: loading and error states, orbit/zoom, auto-rotate toggle, "Studio"/"Soft" lighting presets, disposes GPU resources on unmount, fills its parent's width at a 1:1 aspect ratio.

- [ ] **Step 1: Install Three.js**

```bash
npm install three
npm install -D @types/three
```

- [ ] **Step 2: Write the component**

`resources/js/components/ModelViewer.vue`:

```vue
<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import * as THREE from 'three';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';

const props = withDefaults(defineProps<{ src: string; autoRotate?: boolean }>(), { autoRotate: true });

const container = ref<HTMLDivElement | null>(null);
const state = ref<'loading' | 'ready' | 'error'>('loading');
const rotating = ref(props.autoRotate);
const preset = ref<'studio' | 'soft'>('studio');

let renderer: THREE.WebGLRenderer | null = null;
let scene: THREE.Scene | null = null;
let camera: THREE.PerspectiveCamera | null = null;
let controls: OrbitControls | null = null;
let model: THREE.Object3D | null = null;
let frame = 0;
let resizeObserver: ResizeObserver | null = null;
let lights: THREE.Light[] = [];

function applyPreset() {
    if (!scene) return;
    lights.forEach((l) => scene!.remove(l));
    lights = preset.value === 'studio'
        ? [new THREE.HemisphereLight(0xffffff, 0x666677, 1.1), Object.assign(new THREE.DirectionalLight(0xffffff, 2.2), { position: new THREE.Vector3(3, 5, 4) })]
        : [new THREE.HemisphereLight(0xffffff, 0xddddee, 2.2), Object.assign(new THREE.DirectionalLight(0xfff2e0, 0.6), { position: new THREE.Vector3(-2, 3, 2) })];
    lights.forEach((l) => scene!.add(l));
}

function resize() {
    if (!container.value || !renderer || !camera) return;
    const { clientWidth: w, clientHeight: h } = container.value;
    if (w === 0 || h === 0) return;
    renderer.setSize(w, h);
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
}

function frameModel(object: THREE.Object3D) {
    const box = new THREE.Box3().setFromObject(object);
    const size = box.getSize(new THREE.Vector3());
    const center = box.getCenter(new THREE.Vector3());
    object.position.sub(center);

    const maxDim = Math.max(size.x, size.y, size.z) || 1;
    camera!.near = maxDim / 100;
    camera!.far = maxDim * 100;
    camera!.position.set(0, maxDim * 0.3, maxDim * 2.2);
    camera!.updateProjectionMatrix();
    controls!.target.set(0, 0, 0);
    controls!.update();
}

function disposeModel() {
    if (!model || !scene) return;
    scene.remove(model);
    model.traverse((node) => {
        const mesh = node as THREE.Mesh;
        if (!mesh.isMesh) return;
        mesh.geometry.dispose();
        (Array.isArray(mesh.material) ? mesh.material : [mesh.material]).forEach((m) => m.dispose());
    });
    model = null;
}

function load(url: string) {
    state.value = 'loading';
    disposeModel();
    new GLTFLoader().load(
        url,
        (gltf) => {
            if (!scene) return;
            model = gltf.scene;
            scene.add(model);
            frameModel(model);
            state.value = 'ready';
        },
        undefined,
        () => {
            state.value = 'error';
        },
    );
}

function tick() {
    frame = requestAnimationFrame(tick);
    controls?.update();
    if (renderer && scene && camera) renderer.render(scene, camera);
}

onMounted(() => {
    if (!container.value) return;

    renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    container.value.appendChild(renderer.domElement);

    scene = new THREE.Scene();
    camera = new THREE.PerspectiveCamera(40, 1, 0.01, 100);
    controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.autoRotate = rotating.value;
    controls.autoRotateSpeed = 1.5;

    applyPreset();
    resize();
    resizeObserver = new ResizeObserver(resize);
    resizeObserver.observe(container.value);
    load(props.src);
    tick();
});

watch(() => props.src, load);
watch(preset, applyPreset);
watch(rotating, (value) => {
    if (controls) controls.autoRotate = value;
});

onBeforeUnmount(() => {
    cancelAnimationFrame(frame);
    resizeObserver?.disconnect();
    disposeModel();
    controls?.dispose();
    renderer?.dispose();
    renderer?.domElement.remove();
    renderer = scene = camera = controls = null;
});
</script>

<template>
    <div class="relative aspect-square w-full overflow-hidden rounded-xl border bg-muted/40">
        <div ref="container" class="absolute inset-0" />

        <div v-if="state === 'loading'" class="absolute inset-0 flex items-center justify-center text-sm text-muted-foreground">
            Loading model…
        </div>
        <div v-else-if="state === 'error'" class="absolute inset-0 flex items-center justify-center px-6 text-center text-sm text-destructive">
            This model could not be loaded.
        </div>

        <div v-if="state === 'ready'" class="absolute bottom-3 left-3 flex gap-2 text-xs">
            <button type="button" class="rounded-md border bg-background/80 px-2 py-1 backdrop-blur" @click="rotating = !rotating">
                {{ rotating ? 'Pause' : 'Rotate' }}
            </button>
            <button
                type="button"
                class="rounded-md border bg-background/80 px-2 py-1 backdrop-blur"
                @click="preset = preset === 'studio' ? 'soft' : 'studio'"
            >
                Light: {{ preset === 'studio' ? 'Studio' : 'Soft' }}
            </button>
        </div>
    </div>
</template>
```

- [ ] **Step 3: Type-check and build**

```bash
npx vue-tsc --noEmit
npm run build
```
Expected: no type errors, build succeeds. Fix any strict-mode complaints in the component (do not weaken tsconfig).

- [ ] **Step 4: Commit**

```bash
git add package.json package-lock.json resources
git commit -m "feat: add Three.js ModelViewer component

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Create page

**Files:**
- Create: `resources/js/pages/Create.vue`

**Interfaces:**
- Consumes: Inertia props `{ styles: StyleOption[]; balance: number }` from `GET /create` (Task 7); posts multipart to `POST /creations` with fields `photo` and `style_id`; error keys `photo`, `style_id`.
- Layout/imports: mirror `resources/js/pages/Dashboard.vue` (noted in Task 1 Step 4). The code below assumes `AppLayout` at `@/layouts/AppLayout.vue`, `Button` at `@/components/ui/button`, and `BreadcrumbItem` from `@/types`; adjust only the import lines if the scaffold differs.

- [ ] **Step 1: Write the page**

`resources/js/pages/Create.vue`:

```vue
<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type Subject = 'person' | 'pet' | 'object';
type StyleOption = { id: number; subject: Subject; name: string; look: string; credit_cost: number };

const props = defineProps<{ styles: StyleOption[]; balance: number }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Create', href: '/create' }];
const subjects: { value: Subject; label: string }[] = [
    { value: 'person', label: 'Person' },
    { value: 'pet', label: 'Pet' },
    { value: 'object', label: 'Object' },
];
const MAX_BYTES = 10 * 1024 * 1024;
const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];

const form = useForm<{ photo: File | null; style_id: number | null }>({ photo: null, style_id: null });
const subject = ref<Subject>('person');
const previewUrl = ref<string | null>(null);
const dragging = ref(false);
const clientError = ref<string | null>(null);

const visibleStyles = computed(() => props.styles.filter((s) => s.subject === subject.value));
const selected = computed(() => props.styles.find((s) => s.id === form.style_id) ?? null);
const cost = computed(() => selected.value?.credit_cost ?? 0);
const canAfford = computed(() => props.balance >= cost.value);
const canSubmit = computed(() => !!form.photo && !!selected.value && canAfford.value && !form.processing);

function setPhoto(file: File | undefined) {
    if (!file) return;
    if (!ALLOWED.includes(file.type)) {
        clientError.value = 'Use a JPG, PNG, or WebP photo.';
        return;
    }
    if (file.size > MAX_BYTES) {
        clientError.value = 'The photo must be 10 MB or smaller.';
        return;
    }
    clientError.value = null;
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = URL.createObjectURL(file);
    form.photo = file;
}

function onPick(event: Event) {
    setPhoto((event.target as HTMLInputElement).files?.[0]);
}

function onDrop(event: DragEvent) {
    dragging.value = false;
    setPhoto(event.dataTransfer?.files?.[0]);
}

function selectSubject(value: Subject) {
    subject.value = value;
    if (selected.value && selected.value.subject !== value) form.style_id = null;
}

function submit() {
    form.post('/creations', { forceFormData: true });
}

onBeforeUnmount(() => {
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
});
</script>

<template>
    <Head title="Create" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-4xl flex-col gap-8 p-4 md:p-6">
            <header>
                <h1 class="text-2xl font-semibold tracking-tight">Turn a photo into a 3D model</h1>
                <p class="mt-1 text-sm text-muted-foreground">Upload a photo, choose a style, and we'll build a 3D model you can view and download.</p>
            </header>

            <!-- 1. Photo -->
            <section class="space-y-3">
                <h2 class="text-sm font-medium"><span class="text-muted-foreground">1.</span> Upload a photo</h2>
                <label
                    class="flex min-h-56 cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed p-4 text-center transition-colors"
                    :class="dragging ? 'border-primary bg-primary/5' : 'border-border hover:bg-muted/40'"
                    @dragover.prevent="dragging = true"
                    @dragleave.prevent="dragging = false"
                    @drop.prevent="onDrop"
                >
                    <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" @change="onPick" />
                    <img v-if="previewUrl" :src="previewUrl" alt="Selected photo" class="max-h-64 rounded-lg object-contain" />
                    <template v-else>
                        <span class="font-medium">Drop a photo here or click to browse</span>
                        <span class="text-xs text-muted-foreground">JPG, PNG or WebP · up to 10 MB · at least 512 × 512 px</span>
                    </template>
                </label>
                <p class="text-xs text-muted-foreground">Tip: a front-facing photo with good lighting and a plain background gives the best results.</p>
                <p v-if="clientError || form.errors.photo" class="text-sm text-destructive">{{ clientError ?? form.errors.photo }}</p>
            </section>

            <!-- 2. Style -->
            <section class="space-y-3">
                <h2 class="text-sm font-medium"><span class="text-muted-foreground">2.</span> Choose a style</h2>
                <div class="flex gap-2" role="tablist">
                    <button
                        v-for="s in subjects"
                        :key="s.value"
                        type="button"
                        role="tab"
                        :aria-selected="subject === s.value"
                        class="rounded-full border px-4 py-1.5 text-sm transition-colors"
                        :class="subject === s.value ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted'"
                        @click="selectSubject(s.value)"
                    >
                        {{ s.label }}
                    </button>
                </div>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <button
                        v-for="style in visibleStyles"
                        :key="style.id"
                        type="button"
                        :aria-pressed="form.style_id === style.id"
                        class="rounded-xl border p-4 text-left transition-colors"
                        :class="form.style_id === style.id ? 'border-primary ring-2 ring-primary/30' : 'hover:bg-muted/40'"
                        @click="form.style_id = style.id"
                    >
                        <span class="block font-medium">{{ style.name }}</span>
                        <span class="mt-1 block text-xs text-muted-foreground">{{ style.credit_cost }} credits</span>
                    </button>
                </div>
                <p v-if="visibleStyles.length === 0" class="text-sm text-muted-foreground">No styles available for this subject yet.</p>
                <p v-if="form.errors.style_id" class="text-sm text-destructive">{{ form.errors.style_id }}</p>
            </section>

            <!-- 3. Review -->
            <section class="flex flex-col gap-3 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="text-sm">
                    <div>Balance: <strong>{{ balance }}</strong> credits</div>
                    <div v-if="selected" class="text-muted-foreground">This will cost {{ cost }} credits. Credits are refunded if generation fails.</div>
                </div>
                <div class="flex items-center gap-3">
                    <Link v-if="selected && !canAfford" href="/credits" class="text-sm underline">Add credits</Link>
                    <Button :disabled="!canSubmit" @click="submit">
                        {{ form.processing ? 'Uploading…' : selected ? `Generate (${cost} credits)` : 'Generate' }}
                    </Button>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
```

- [ ] **Step 2: Type-check**

Run: `npx vue-tsc --noEmit`
Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add resources
git commit -m "feat: add create page with upload, style picker, and cost review

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Creations list and creation detail pages

**Files:**
- Create: `resources/js/pages/creations/Index.vue`, `resources/js/pages/creations/Show.vue`

**Interfaces:**
- Consumes: `CreationPayload` (Task 7): `{ id, status: 'queued'|'processing'|'succeeded'|'failed', error, progress, cost_credits, created_at, style: {name, subject, look}, urls: {source, model, thumbnail, download} }`; `ModelViewer` (Task 9); `DELETE /creations/{id}`.
- `Show.vue` polls `router.reload({ only: ['creation'] })` every 3000 ms while `queued`/`processing`.

- [ ] **Step 1: Index page**

`resources/js/pages/creations/Index.vue`:

```vue
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type Creation = {
    id: number;
    status: 'queued' | 'processing' | 'succeeded' | 'failed';
    created_at: string;
    style: { name: string; subject: string; look: string };
    urls: { source: string; thumbnail: string | null };
};

defineProps<{ creations: Creation[] }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'My Creations', href: '/creations' }];
const badge: Record<Creation['status'], string> = {
    queued: 'bg-muted text-muted-foreground',
    processing: 'bg-amber-100 text-amber-900 dark:bg-amber-900/30 dark:text-amber-200',
    succeeded: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-200',
    failed: 'bg-red-100 text-red-900 dark:bg-red-900/30 dark:text-red-200',
};
const date = (iso: string) => new Date(iso).toLocaleDateString(undefined, { dateStyle: 'medium' });
</script>

<template>
    <Head title="My Creations" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
            <header class="flex items-center justify-between">
                <h1 class="text-2xl font-semibold tracking-tight">My Creations</h1>
                <Button as-child><Link href="/create">New creation</Link></Button>
            </header>

            <div v-if="creations.length === 0" class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
                You haven't made anything yet.
                <Link href="/create" class="underline">Create your first 3D model</Link>.
            </div>

            <div v-else class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
                <Link
                    v-for="c in creations"
                    :key="c.id"
                    :href="`/creations/${c.id}`"
                    class="group overflow-hidden rounded-xl border transition-shadow hover:shadow-md"
                >
                    <div class="aspect-square bg-muted/40">
                        <img :src="c.urls.thumbnail ?? c.urls.source" :alt="c.style.name" class="size-full object-cover" loading="lazy" />
                    </div>
                    <div class="flex items-center justify-between gap-2 p-3 text-sm">
                        <div class="min-w-0">
                            <div class="truncate font-medium">{{ c.style.name }} {{ c.style.subject }}</div>
                            <div class="text-xs text-muted-foreground">{{ date(c.created_at) }}</div>
                        </div>
                        <span class="shrink-0 rounded-full px-2 py-0.5 text-xs capitalize" :class="badge[c.status]">{{ c.status }}</span>
                    </div>
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
```

- [ ] **Step 2: Show page**

`resources/js/pages/creations/Show.vue`:

```vue
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import ModelViewer from '@/components/ModelViewer.vue';
import { Button } from '@/components/ui/button';
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

const POLL_MS = 3000;
let timer: ReturnType<typeof setInterval> | null = null;

const working = computed(() => props.creation.status === 'queued' || props.creation.status === 'processing');
const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'My Creations', href: '/creations' },
    { title: `${props.creation.style.name} ${props.creation.style.subject}`, href: `/creations/${props.creation.id}` },
]);

function startPolling() {
    if (timer) return;
    timer = setInterval(() => {
        if (!working.value) return stopPolling();
        router.reload({ only: ['creation'] });
    }, POLL_MS);
}

function stopPolling() {
    if (timer) clearInterval(timer);
    timer = null;
}

function remove() {
    if (confirm('Delete this creation? This cannot be undone.')) router.delete(`/creations/${props.creation.id}`);
}

onMounted(() => working.value && startPolling());
onBeforeUnmount(stopPolling);
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
                <img :src="creation.urls.source" alt="Your photo" class="aspect-square w-full rounded-xl border object-cover" />
                <div class="flex flex-col justify-center gap-3">
                    <p class="font-medium">Building your 3D model…</p>
                    <div class="h-2 overflow-hidden rounded-full bg-muted" role="progressbar" :aria-valuenow="creation.progress ?? 0" aria-valuemin="0" aria-valuemax="100">
                        <div class="h-full bg-primary transition-all" :style="{ width: `${creation.progress ?? 5}%` }" />
                    </div>
                    <p class="text-sm text-muted-foreground">This can take a few minutes. You can leave this page — it will keep going and appear in My Creations.</p>
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

            <!-- failed -->
            <section v-else class="space-y-4 rounded-xl border border-destructive/40 p-5">
                <p class="font-medium text-destructive">We couldn't build this model.</p>
                <p v-if="creation.error" class="text-sm text-muted-foreground">{{ creation.error }}</p>
                <p class="text-sm">Your {{ creation.cost_credits }} credits have been refunded.</p>
                <div class="flex gap-3">
                    <Button as-child><Link href="/create">Try again</Link></Button>
                    <Button variant="outline" @click="remove">Delete</Button>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
```

- [ ] **Step 3: Type-check**

Run: `npx vue-tsc --noEmit`
Expected: no errors. If `Button` has no `as-child` prop in the scaffold's shadcn-vue version, check `resources/js/components/ui/button/Button.vue`; use `<Link class="...">` styled with the `buttonVariants()` export instead.

- [ ] **Step 4: Commit**

```bash
git add resources
git commit -m "feat: add creations list and detail pages with status polling

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 12: Credits page, navigation, landing page

**Files:**
- Create: `resources/js/pages/Credits.vue`
- Modify: `resources/js/pages/Welcome.vue` (full rewrite), `resources/js/components/AppSidebar.vue` (nav items)
- Delete: `resources/js/pages/Dashboard.vue`

**Interfaces:**
- Consumes: Credits props `{ balance, ledger: [{id, delta, reason, created_at}], topup: {enabled, amount} }` (Task 8); `ModelViewer` (Task 9); public `GET /samples/demo.glb` (Task 7); the scaffold's `SharedData` type (`page.props.auth.user`) and `canRegister` prop on the welcome route.

- [ ] **Step 1: Credits page**

`resources/js/pages/Credits.vue`:

```vue
<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type Entry = { id: number; delta: number; reason: 'signup' | 'topup' | 'generation' | 'refund'; created_at: string };

defineProps<{ balance: number; ledger: Entry[]; topup: { enabled: boolean; amount: number } }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Credits', href: '/credits' }];
const label: Record<Entry['reason'], string> = {
    signup: 'Welcome bonus',
    topup: 'Credits added',
    generation: 'Model generation',
    refund: 'Refund (failed generation)',
};
const busy = ref(false);
const when = (iso: string) => new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });

function addCredits() {
    router.post('/credits/topup', {}, { preserveScroll: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false) });
}
</script>

<template>
    <Head title="Credits" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
            <section class="flex flex-col gap-4 rounded-xl border p-5 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm text-muted-foreground">Your balance</p>
                    <p class="text-4xl font-semibold tabular-nums">{{ balance }} <span class="text-base font-normal text-muted-foreground">credits</span></p>
                </div>
                <div v-if="topup.enabled" class="space-y-1 sm:text-right">
                    <Button :disabled="busy" @click="addCredits">Add {{ topup.amount }} credits</Button>
                    <p class="text-xs text-muted-foreground">Demo only — real payments come later.</p>
                </div>
            </section>

            <section class="space-y-3">
                <h2 class="text-sm font-medium">History</h2>
                <ul v-if="ledger.length" class="divide-y rounded-xl border">
                    <li v-for="entry in ledger" :key="entry.id" class="flex items-center justify-between gap-4 px-4 py-3 text-sm">
                        <div>
                            <div>{{ label[entry.reason] }}</div>
                            <div class="text-xs text-muted-foreground">{{ when(entry.created_at) }}</div>
                        </div>
                        <span class="font-medium tabular-nums" :class="entry.delta > 0 ? 'text-emerald-600 dark:text-emerald-400' : ''">
                            {{ entry.delta > 0 ? '+' : '' }}{{ entry.delta }}
                        </span>
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">No activity yet.</p>
            </section>
        </div>
    </AppLayout>
</template>
```

- [ ] **Step 2: Navigation**

Read `resources/js/components/AppSidebar.vue`. Replace the main nav items array (the kit's single Dashboard entry) with the three below, keeping the kit's item shape (`title`, `href`, `icon`) and importing the icons from `lucide-vue-next`:

```ts
import { Box, Coins, Sparkles } from 'lucide-vue-next';

const mainNavItems: NavItem[] = [
    { title: 'Create', href: '/create', icon: Sparkles },
    { title: 'My Creations', href: '/creations', icon: Box },
    { title: 'Credits', href: '/credits', icon: Coins },
];
```

If the sidebar's logo link points to the dashboard route, point it at `/creations`. Leave the footer/user menu untouched.

- [ ] **Step 3: Landing page**

Read `resources/js/pages/Welcome.vue` first to see how it reads `canRegister` and the user; reuse the same `usePage`/`SharedData` access pattern. Replace the file with:

```vue
<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ModelViewer from '@/components/ModelViewer.vue';
import { Button } from '@/components/ui/button';
import type { SharedData } from '@/types';

withDefaults(defineProps<{ canRegister?: boolean }>(), { canRegister: true });

const page = usePage<SharedData>();
const user = computed(() => page.props.auth.user);

const steps = [
    { title: 'Upload', body: 'Add a clear photo of a person, pet, or favourite object.' },
    { title: 'Pick a look', body: 'Realistic, cartoon, clay, or chibi — choose the style you like.' },
    { title: 'Get your 3D model', body: 'Spin it around in your browser and download it to print or share.' },
];
</script>

<template>
    <Head title="Your photo into a 3D figurine" />

    <div class="min-h-screen bg-background text-foreground">
        <header class="mx-auto flex max-w-6xl items-center justify-between px-4 py-4">
            <Link href="/" class="text-lg font-semibold tracking-tight">3D Maker</Link>
            <nav class="flex items-center gap-2">
                <template v-if="user">
                    <Button as-child><Link href="/create">Create</Link></Button>
                </template>
                <template v-else>
                    <Button variant="ghost" as-child><Link href="/login">Log in</Link></Button>
                    <Button v-if="canRegister" as-child><Link href="/register">Register</Link></Button>
                </template>
            </nav>
        </header>

        <main>
            <section class="mx-auto grid max-w-6xl items-center gap-10 px-4 py-12 md:grid-cols-2 md:py-20">
                <div class="space-y-6">
                    <h1 class="text-4xl font-semibold tracking-tight md:text-5xl">Your photo into a 3D figurine</h1>
                    <p class="max-w-md text-lg text-muted-foreground">
                        Turn a photo of yourself, your pet, or something you love into a 3D model you can view in your browser and download.
                    </p>
                    <div class="flex flex-wrap gap-3">
                        <Button size="lg" as-child><Link :href="user ? '/create' : '/register'">Start creating for free</Link></Button>
                    </div>
                    <p class="text-sm text-muted-foreground">New accounts start with free credits.</p>
                </div>
                <ModelViewer src="/samples/demo.glb" />
            </section>

            <section class="mx-auto max-w-6xl px-4 py-12">
                <h2 class="mb-8 text-2xl font-semibold tracking-tight">How it works</h2>
                <ol class="grid gap-6 md:grid-cols-3">
                    <li v-for="(step, i) in steps" :key="step.title" class="rounded-xl border p-5">
                        <span class="text-sm text-muted-foreground">Step {{ i + 1 }}</span>
                        <h3 class="mt-1 font-medium">{{ step.title }}</h3>
                        <p class="mt-2 text-sm text-muted-foreground">{{ step.body }}</p>
                    </li>
                </ol>
            </section>

            <section class="mx-auto max-w-6xl px-4 py-16 text-center">
                <h2 class="text-2xl font-semibold tracking-tight">Ready to make your first model?</h2>
                <div class="mt-6">
                    <Button size="lg" as-child><Link :href="user ? '/create' : '/register'">Start creating for free</Link></Button>
                </div>
            </section>
        </main>
    </div>
</template>
```

- [ ] **Step 4: Remove the unused dashboard page**

```bash
git rm resources/js/pages/Dashboard.vue
```
Search for leftover references: use Grep for `Dashboard` / `dashboard` under `resources/js` and `app`, and fix any link that still points at the removed page (the `/dashboard` route redirects, so links keep working, but update obvious ones to `/creations`).

- [ ] **Step 5: Type-check, build, and run the full suite**

```bash
npx vue-tsc --noEmit
npm run build
php artisan test
```
Expected: all green. If you added `inertia.testing.ensure_pages_exist` overrides in Task 7, remove them now. Because all pages exist now, `assertInertia` component checks pass without them.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add credits page, navigation, and landing page

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 13: End-to-end verification, formatting, README, push

**Files:**
- Create: `README.md` (replace the kit's)
- Modify: formatting only, via Pint

**Interfaces:** none; this task verifies the whole feature.

- [ ] **Step 1: Format and lint**

```bash
./vendor/bin/pint
npm run lint 2>&1 | tail -5     # if the script exists; otherwise skip
php artisan test
```
Expected: Pint reports fixes or none, suite PASS.

- [ ] **Step 2: Run the app against MySQL with the mock provider**

`composer run dev` starts `pail`, which needs the `pcntl` extension (unavailable on Windows). Run the three processes separately, each in its own terminal (background them in your tooling):

```bash
php artisan serve
php artisan queue:listen --timeout=0
npm run dev
```

Then open `http://127.0.0.1:8000` and walk through the flow in a browser (use the Browser tools):

1. Landing page shows the spinning demo cube and "How it works". No console errors.
2. Register a new user. You land on **My Creations** (empty). Open **Credits**: balance is 20, history shows "Welcome bonus +20".
3. **Create**: upload any photo ≥ 512×512, choose Person → Cartoon (5 credits), submit.
4. The creation page shows "Building your 3D model…" with a moving progress bar for about 6 seconds, then switches to the viewer showing a coloured cube that you can orbit and zoom. "Download GLB" downloads `creation-1.glb`.
5. **Credits**: balance 15, history shows "Model generation −5".
6. Set `MODEL_MOCK_FAIL_RATE=1` in `.env`, restart the queue listener, create again: the page ends in the failed state, says credits were refunded, and **Credits** shows "Refund +5", balance back to 15.
7. Reset `MODEL_MOCK_FAIL_RATE=0`. Try submitting with a style costing more than the balance (temporarily `UPDATE styles SET credit_cost = 500 WHERE id = 1`, then restore to 6): the Generate button is disabled and "Add credits" links to `/credits`.
8. Delete a finished creation: it disappears from the list.
9. Log in as a second user: `/creations/1` returns 403 and `/creations/1/files/model` returns 403.

Fix any defect found by writing a failing test first, then the fix.

- [ ] **Step 3: README**

Replace `README.md` with:

```markdown
# 3D Maker

Turn a photo of a person, pet, or object into a downloadable 3D model. Laravel 12 + Inertia/Vue + MySQL.

## Setup (Laragon)

1. `composer install && npm install`
2. Copy `.env.example` to `.env`, then `php artisan key:generate`
3. Create the MySQL database `three_d_maker` and set `DB_*` in `.env`
4. `php artisan migrate --seed`
5. Run in three terminals: `php artisan serve`, `php artisan queue:listen --timeout=0`, `npm run dev`

## Model provider

`MODEL_PROVIDER=mock` (default) generates a placeholder cube after `MODEL_MOCK_DELAY_SECONDS`.
Set `MODEL_MOCK_FAIL_RATE` (0–1) to exercise the refund path. Real providers implement
`App\Services\ModelProviders\ModelProvider`.

## Tests

`php artisan test`

## Docs

Design specs: `docs/superpowers/specs/`. Implementation plans: `docs/superpowers/plans/`.
```

- [ ] **Step 4: Final suite run, commit, and push**

```bash
php artisan test
git add -A
git commit -m "docs: add README and format code

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
git push
```
Expected: all tests PASS and the push succeeds to `origin/main`.

---

## Self-Review (spec coverage)

| Spec section | Covered by |
|---|---|
| §2 stack, status polling, stubbed payments | Task 1 (stack), Task 11 (3 s polling), Task 8 (stub top-up) |
| §4 data model (users, styles, creations, credit_ledger, unique refund constraint) | Task 2, Task 3 |
| §5 generation flow (transactional charge, queue, success/failure/refund, polling UI) | Tasks 3, 6, 7, 11 |
| §6 provider adapter (interface, `ProviderResult`, mock with fail rate, config binding, style param mapping, job timeout/backoff/transient vs permanent/idempotent/download) | Tasks 4, 6 (`style.provider_params` is passed to providers via `start($path, $style)`; the mock ignores it) |
| §7 pages (landing, auth, create, list, creation, credits) and viewer | Tasks 9–12; auth pages come from the starter kit and restyle is limited to the kit's theme (spec: "restyled"; do a visual pass in Task 13 Step 2 if the kit's look clashes) |
| §8 private disk, re-encode, rate limit, policies | Tasks 5, 7 |
| §9 testing | Every task ships its tests; job tests cover success/failure/timeout with ledger assertions |

**Deviations from the spec (documented, deliberate):**
1. `ModelProvider` gains `download(string $url): string` so the job can fetch expiring provider URLs through the provider (the mock generates bytes in-process, so no binary fixtures live in the repo).
2. "Transient errors retry a few times" is implemented as "retry every 10 s until the overall 10-minute deadline" (simpler, still bounded, refunds on expiry).
3. The stub top-up is disabled by default when `APP_ENV=production`.
