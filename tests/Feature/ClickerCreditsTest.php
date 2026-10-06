<?php

use App\Enums\LedgerReason;
use App\Exceptions\InsufficientCreditsException;
use App\Models\Clicker;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Services\Credits\CreditService;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function () {
    $this->credits = app(CreditService::class);
    $this->user = User::factory()->create();
    $this->credits->grant($this->user, 120, LedgerReason::Signup);
    $this->clicker = Clicker::factory()->create(['user_id' => $this->user->id]);
});

it('exposes the unlock price in config', function () {
    expect(config('credits.clicker_cost'))->toBe(50);
});

it('charges one numbered attempt and links the ledger row to the clicker', function () {
    $entry = $this->credits->spendForClicker($this->user, 50, $this->clicker, 1);

    expect($entry->delta)->toBe(-50)
        ->and($entry->reason)->toBe(LedgerReason::ClickerDownload)
        ->and($entry->clicker_id)->toBe($this->clicker->id)
        ->and($entry->clicker_attempt)->toBe(1)
        ->and($this->credits->balance($this->user))->toBe(70);
});

it('refuses to charge more than the balance and writes nothing', function () {
    expect(fn () => $this->credits->spendForClicker($this->user, 500, $this->clicker, 1))
        ->toThrow(InsufficientCreditsException::class);

    expect(CreditLedgerEntry::where('reason', LedgerReason::ClickerDownload)->count())->toBe(0)
        ->and($this->credits->balance($this->user))->toBe(120);
});

it('cannot charge the same attempt twice', function () {
    $this->credits->spendForClicker($this->user, 50, $this->clicker, 1);

    expect(fn () => $this->credits->spendForClicker($this->user, 50, $this->clicker, 1))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('refunds an attempt exactly once', function () {
    $this->credits->spendForClicker($this->user, 50, $this->clicker, 1);

    $first = $this->credits->refundClicker($this->clicker, 1);
    $second = $this->credits->refundClicker($this->clicker, 1);

    expect($first->delta)->toBe(50)
        ->and($first->reason)->toBe(LedgerReason::ClickerRefund)
        ->and($second)->toBeNull()
        ->and($this->credits->balance($this->user))->toBe(120);
});

it('refunds nothing for an attempt that was never charged', function () {
    expect($this->credits->refundClicker($this->clicker, 1))->toBeNull();
});

it('lets a second attempt be charged and refunded after the first was refunded', function () {
    $this->credits->spendForClicker($this->user, 50, $this->clicker, 1);
    $this->credits->refundClicker($this->clicker, 1);

    $this->credits->spendForClicker($this->user, 50, $this->clicker, 2);
    expect($this->credits->balance($this->user))->toBe(70);

    $this->credits->refundClicker($this->clicker, 2);
    expect($this->credits->balance($this->user))->toBe(120)
        ->and(CreditLedgerEntry::where('clicker_id', $this->clicker->id)->count())->toBe(4);
});

it('only refunds the attempt it is asked about', function () {
    $this->credits->spendForClicker($this->user, 50, $this->clicker, 1);
    $this->credits->refundClicker($this->clicker, 1);
    $this->credits->spendForClicker($this->user, 50, $this->clicker, 2);

    expect($this->credits->refundClicker($this->clicker, 1))->toBeNull()
        ->and($this->credits->balance($this->user))->toBe(70);
});
