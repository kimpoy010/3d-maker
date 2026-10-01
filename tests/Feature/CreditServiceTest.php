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
