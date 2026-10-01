<?php

use App\Enums\CreationStatus;
use App\Enums\LedgerReason;
use App\Models\Creation;
use App\Models\Style;
use App\Models\User;
use App\Services\Credits\CreditService;

beforeEach(function () {
    config(['models.timeout_seconds' => 600]);

    $this->credits = app(CreditService::class);
    $this->user = User::factory()->create();
    $this->credits->grant($this->user, 20, LedgerReason::Signup);

    $this->charged = function (CreationStatus $status, int $ageSeconds) {
        $creation = Creation::factory()->create([
            'user_id' => $this->user->id,
            'style_id' => Style::factory(),
            'status' => $status,
            'created_at' => now()->subSeconds($ageSeconds),
        ]);
        $this->credits->spend($this->user, 5, $creation);

        return $creation;
    };
});

it('fails and refunds a stale creation exactly once', function () {
    $stale = ($this->charged)(CreationStatus::Queued, 721);
    expect($this->credits->balance($this->user))->toBe(15);

    $this->artisan('creations:sweep')->expectsOutputToContain('1')->assertSuccessful();
    $this->artisan('creations:sweep')->expectsOutputToContain('0')->assertSuccessful();

    $stale->refresh();
    expect($stale->status)->toBe(CreationStatus::Failed)
        ->and($stale->error)->toBe('Generation timed out.')
        ->and($this->credits->balance($this->user))->toBe(20);
});

it('also sweeps stale processing creations', function () {
    $stale = ($this->charged)(CreationStatus::Processing, 5000);

    $this->artisan('creations:sweep')->assertSuccessful();

    expect($stale->refresh()->status)->toBe(CreationStatus::Failed)
        ->and($this->credits->balance($this->user))->toBe(20);
});

it('leaves fresh creations alone', function () {
    $fresh = ($this->charged)(CreationStatus::Queued, 100);
    $withinGrace = ($this->charged)(CreationStatus::Processing, 700);

    $this->artisan('creations:sweep')->assertSuccessful();

    expect($fresh->refresh()->status)->toBe(CreationStatus::Queued)
        ->and($withinGrace->refresh()->status)->toBe(CreationStatus::Processing)
        ->and($this->credits->balance($this->user))->toBe(10);
});

it('leaves finished creations alone', function () {
    $succeeded = ($this->charged)(CreationStatus::Succeeded, 5000);
    $failed = ($this->charged)(CreationStatus::Failed, 5000);

    $this->artisan('creations:sweep')->assertSuccessful();

    expect($succeeded->refresh()->status)->toBe(CreationStatus::Succeeded)
        ->and($failed->refresh()->status)->toBe(CreationStatus::Failed)
        ->and($this->credits->balance($this->user))->toBe(10);
});
