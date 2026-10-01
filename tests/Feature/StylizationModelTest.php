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
