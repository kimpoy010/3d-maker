<?php

use App\Enums\ClickerStatus;
use App\Enums\LedgerReason;
use App\Models\Clicker;
use App\Models\ClickerBody;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Services\Credits\CreditService;

it('casts a body and a clicker', function () {
    $body = ClickerBody::factory()->create();
    $clicker = Clicker::factory()->create(['clicker_body_id' => $body->id]);

    $fresh = Clicker::find($clicker->id);

    expect($body->fresh()->config['cap']['shape'])->toBe('rounded')
        ->and($body->fresh()->model_files)->toBeArray()
        ->and($body->fresh()->active)->toBeTrue()
        ->and($fresh->status)->toBe(ClickerStatus::Draft)
        ->and($fresh->settings['colors'])->toHaveCount(2)
        ->and($fresh->isUnlocked())->toBeFalse()
        ->and($fresh->directory())->toBe("clickers/{$clicker->id}")
        ->and($fresh->body->is($body))->toBeTrue()
        ->and($fresh->user)->toBeInstanceOf(User::class);
});

it('filters active bodies', function () {
    ClickerBody::factory()->create();
    ClickerBody::factory()->create(['active' => false]);

    expect(ClickerBody::active()->count())->toBe(1);
});

it('is unlocked once downloads_unlocked_at is set', function () {
    $clicker = Clicker::factory()->create(['downloads_unlocked_at' => now()]);

    expect($clicker->fresh()->isUnlocked())->toBeTrue();
});

it('keeps the ledger row but forgets the clicker when the clicker is deleted', function () {
    $clicker = Clicker::factory()->create();
    $credits = app(CreditService::class);
    $credits->grant($clicker->user, 100, LedgerReason::Signup);
    $credits->spendForClicker($clicker->user, 50, $clicker, 1);

    $clicker->delete();

    $row = CreditLedgerEntry::where('reason', LedgerReason::ClickerDownload)->first();
    expect($row)->not->toBeNull()->and($row->clicker_id)->toBeNull();
});
