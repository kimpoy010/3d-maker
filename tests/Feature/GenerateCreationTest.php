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
