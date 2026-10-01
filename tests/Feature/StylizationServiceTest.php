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

    it('keeps the files of a preview that is still being made', function () {
        Storage::disk('local')->put('stylizations/x/original.jpg', 'o');
        $this->stylization->update(['source_image_path' => 'stylizations/x/original.jpg']);

        expect($this->service->discard($this->stylization->fresh()))->toBeFalse();

        Storage::disk('local')->assertExists('stylizations/x/original.jpg');
        expect($this->stylization->fresh()->source_image_path)->toBe('stylizations/x/original.jpg');
    });

    it('discards a failed preview and deletes its files', function () {
        Storage::disk('local')->put('stylizations/x/original.jpg', 'o');
        $this->stylization->update(['source_image_path' => 'stylizations/x/original.jpg']);
        $this->service->markFailed($this->stylization->id, 'nope');

        expect($this->service->discard($this->stylization->fresh()))->toBeTrue()
            ->and($this->stylization->fresh()->status)->toBe(StylizationStatus::Discarded);
        Storage::disk('local')->assertMissing('stylizations/x/original.jpg');
    });

    it('deletes an orphaned result file when a preview fails', function () {
        $path = "stylizations/{$this->stylization->id}/result.png";
        Storage::disk('local')->put($path, 'orphan');
        $this->service->markProcessing($this->stylization->id);

        expect($this->service->markFailed($this->stylization->id, 'late'))->toBeTrue();

        Storage::disk('local')->assertMissing($path);
    });

    it('does not delete the result file when the preview was not failed by this call', function () {
        $path = "stylizations/{$this->stylization->id}/result.png";
        Storage::disk('local')->put($path, 'real');
        $this->service->markReady($this->stylization->id, $path);

        expect($this->service->markFailed($this->stylization->id, 'late'))->toBeFalse();

        Storage::disk('local')->assertExists($path);
    });
});

it('counts failed and discarded previews toward the daily limit', function () {
    Queue::fake();
    config(['stylizer.daily_limit' => 2]);

    $a = $this->service->create($this->user, ($this->photo)(), $this->style);
    $b = $this->service->create($this->user, ($this->photo)(), $this->style);
    $this->service->markFailed($a->id, 'x');
    $this->service->markFailed($b->id, 'x');
    $this->service->discard($b->fresh());

    expect(fn () => $this->service->create($this->user, ($this->photo)(), $this->style))
        ->toThrow(DailyLimitReachedException::class);
});
