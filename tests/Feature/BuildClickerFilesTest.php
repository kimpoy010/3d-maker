<?php

use App\Enums\ClickerStatus;
use App\Enums\LedgerReason;
use App\Jobs\BuildClickerFiles;
use App\Models\Clicker;
use App\Models\User;
use App\Services\Clickers\ClickerBuilder;
use App\Services\Clickers\ClickerBuildException;
use App\Services\Clickers\ClickerService;
use App\Services\Credits\CreditService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    config(['credits.clicker_cost' => 50]);

    $this->credits = app(CreditService::class);
    $this->service = app(ClickerService::class);
    $this->user = User::factory()->create();
    $this->credits->grant($this->user, 120, LedgerReason::Signup);

    $this->clicker = Clicker::factory()->create(['user_id' => $this->user->id]);
    $this->clicker->update(['artwork_path' => "clickers/{$this->clicker->id}/artwork.png"]);
    Storage::disk('local')->put($this->clicker->artwork_path, fakePngBytes());
    $this->service->unlock($this->user, $this->clicker);

    $this->run = fn () => (new BuildClickerFiles($this->clicker->id))->handle(app(ClickerBuilder::class), $this->service);
});

it('stores the files and marks the design ready', function () {
    app()->bind(ClickerBuilder::class, fn () => new class implements ClickerBuilder
    {
        public function build(Clicker $clicker): array
        {
            Storage::disk('local')->put("{$clicker->directory()}/clicker.3mf", '3mf');
            Storage::disk('local')->put("{$clicker->directory()}/clicker-stl.zip", 'zip');

            return ['threemf' => "{$clicker->directory()}/clicker.3mf", 'stl_zip' => "{$clicker->directory()}/clicker-stl.zip"];
        }
    });

    ($this->run)();

    $fresh = $this->clicker->fresh();
    expect($fresh->status)->toBe(ClickerStatus::Ready)
        ->and($fresh->threemf_path)->toBe("clickers/{$fresh->id}/clicker.3mf")
        ->and($this->credits->balance($this->user))->toBe(70);
});

it('fails the design and refunds when the build fails', function () {
    app()->bind(ClickerBuilder::class, fn () => new class implements ClickerBuilder
    {
        public function build(Clicker $clicker): array
        {
            throw new ClickerBuildException('We could not build your files.');
        }
    });

    ($this->run)();

    $fresh = $this->clicker->fresh();
    expect($fresh->status)->toBe(ClickerStatus::Failed)
        ->and($fresh->build_error)->toBe('We could not build your files.')
        ->and($fresh->isUnlocked())->toBeFalse()
        ->and($this->credits->balance($this->user))->toBe(120);
});

it('does nothing when the design is not building', function () {
    $this->clicker->refresh()->update(['status' => ClickerStatus::Draft]);
    app()->bind(ClickerBuilder::class, fn () => new class implements ClickerBuilder
    {
        public function build(Clicker $clicker): array
        {
            throw new RuntimeException('must not be called');
        }
    });

    ($this->run)();

    expect($this->clicker->fresh()->status)->toBe(ClickerStatus::Draft)
        ->and($this->credits->balance($this->user))->toBe(70);
});

it('refunds when the queue reports the job as failed', function () {
    (new BuildClickerFiles($this->clicker->id))->failed(new RuntimeException('boom'));

    expect($this->clicker->fresh()->status)->toBe(ClickerStatus::Failed)
        ->and($this->credits->balance($this->user))->toBe(120);
});

it('uses an overlap lock that expires between the worker timeout and the queue retry window', function () {
    $job = new BuildClickerFiles(1);
    $lock = $job->middleware()[0];

    expect($lock->expiresAfter)->toBeGreaterThan($job->timeout)
        ->and($lock->expiresAfter)->toBeLessThan(300)
        ->and($job->timeout)->toBeGreaterThan((int) config('clicker.build_timeout_seconds'));
});
