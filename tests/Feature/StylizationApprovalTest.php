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
        $stylization = Stylization::factory()->create($attrs + [
            'user_id' => $this->user->id,
            'style_id' => $this->style->id,
            'status' => StylizationStatus::Ready,
            'source_image_path' => 'stylizations/r/original.jpg',
            'result_image_path' => 'stylizations/r/result.png',
        ]);
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
