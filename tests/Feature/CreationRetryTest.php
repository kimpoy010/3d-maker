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

        return Creation::factory()->create($attrs + [
            'user_id' => $this->user->id,
            'style_id' => $this->style->id,
            'status' => CreationStatus::Failed,
            'source_image_path' => 'uploads/old.jpg',
        ]);
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

it('keeps the new creation and its image when only the dispatch fails', function () {
    $this->app->bind(CreationService::class, fn () => new class(app(CreditService::class)) extends CreationService
    {
        public function dispatchGeneration(Creation $creation): void
        {
            throw new RuntimeException('queue down');
        }
    });
    $failed = ($this->makeFailed)();

    expect(fn () => app(CreationService::class)->retryFailed($this->user, $failed))
        ->toThrow(RuntimeException::class, 'queue down');

    $new = Creation::where('id', '!=', $failed->id)->firstOrFail();
    expect($new->status)->toBe(CreationStatus::Queued)
        ->and($this->credits->balance($this->user))->toBe(15);
    Storage::disk('local')->assertExists($new->source_image_path);
});
