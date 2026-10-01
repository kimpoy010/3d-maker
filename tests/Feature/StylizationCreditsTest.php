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
