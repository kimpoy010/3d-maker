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
use Illuminate\Queue\Middleware\WithoutOverlapping;
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
        ->and($this->credits->balance($this->user))->toBe(20)
        ->and(CreditLedgerEntry::where('reason', LedgerReason::Refund)->count())->toBe(1);
});

it('marks the creation failed and refunds if the job itself blows up', function () {
    $creation = ($this->makeCreation)();

    (new GenerateCreation($creation->id))->failed(new RuntimeException('boom'));

    expect($creation->refresh()->status)->toBe(CreationStatus::Failed)
        ->and($this->credits->balance($this->user))->toBe(20);
});

it('leaves the creation non-terminal when the refund throws and completes it exactly once on re-run', function () {
    config(['models.mock.fail_rate' => 1]);
    $creation = ($this->makeCreation)();

    $this->app->bind(CreditService::class, fn () => new class extends CreditService
    {
        public static bool $thrown = false;

        public function refund(Creation $creation): ?CreditLedgerEntry
        {
            if (! self::$thrown) {
                self::$thrown = true;
                throw new RuntimeException('db down');
            }

            return parent::refund($creation);
        }
    });

    expect(fn () => ($this->runJob)($creation))->toThrow(RuntimeException::class);
    expect($creation->refresh()->status->isFinished())->toBeFalse()
        ->and($this->credits->balance($this->user))->toBe(15);

    ($this->runJob)($creation);
    ($this->runJob)($creation);

    expect($creation->refresh()->status)->toBe(CreationStatus::Failed)
        ->and($this->credits->balance($this->user))->toBe(20)
        ->and(CreditLedgerEntry::where('reason', LedgerReason::Refund)->count())->toBe(1);
});

it('does not fail or refund a creation that another run already succeeded', function () {
    $creation = ($this->makeCreation)();
    $id = $creation->id;

    $this->app->bind(ModelProvider::class, fn () => new class($id) implements ModelProvider
    {
        public function __construct(private int $id) {}

        public function start(string $imagePath, Style $style): string
        {
            return 'job-1';
        }

        public function status(string $providerJobId): ProviderResult
        {
            // Another copy of the job finishes the creation while this one is mid-run.
            Creation::whereKey($this->id)->update(['status' => 'succeeded', 'model_path' => 'x.glb']);

            return new ProviderResult(ProviderState::Failed, error: 'late failure');
        }

        public function download(string $url): string
        {
            return '';
        }
    });

    ($this->runJob)($creation);

    expect($creation->refresh()->status)->toBe(CreationStatus::Succeeded)
        ->and($creation->error)->toBeNull()
        ->and($this->credits->balance($this->user))->toBe(15)
        ->and(CreditLedgerEntry::where('reason', LedgerReason::Refund)->count())->toBe(0);
});

it('does not flip a failed creation to succeeded and removes the files it stored', function () {
    $creation = ($this->makeCreation)();
    $id = $creation->id;
    $credits = $this->credits;
    $user = $this->user;

    $this->app->bind(ModelProvider::class, fn () => new class($id) implements ModelProvider
    {
        public function __construct(private int $id) {}

        public function start(string $imagePath, Style $style): string
        {
            return 'job-1';
        }

        public function status(string $providerJobId): ProviderResult
        {
            return new ProviderResult(ProviderState::Succeeded, modelUrl: 'm', thumbnailUrl: 't');
        }

        public function download(string $url): string
        {
            // Another copy times the creation out and refunds while we download.
            $creation = Creation::find($this->id);
            if (! $creation->status->isFinished()) {
                Creation::whereKey($this->id)->update(['status' => 'failed', 'error' => 'Generation timed out.']);
                app(CreditService::class)->refund($creation);
            }

            return 'data';
        }
    });

    ($this->runJob)($creation);

    expect($creation->refresh()->status)->toBe(CreationStatus::Failed)
        ->and($creation->model_path)->toBeNull()
        ->and($credits->balance($user))->toBe(20);
    Storage::disk('local')->assertMissing("creations/{$id}/model.glb");
    Storage::disk('local')->assertMissing("creations/{$id}/thumbnail.png");
});

it('prevents overlapping runs for the same creation', function () {
    $middleware = (new GenerateCreation(42))->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe(42)
        ->and($middleware[0]->expiresAfter)->toBeLessThan(90);
});
