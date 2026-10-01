<?php

use App\Enums\LedgerReason;
use App\Enums\StylizationStatus;
use App\Models\Style;
use App\Models\Stylization;
use App\Models\User;
use App\Services\Credits\CreditService;

beforeEach(function () {
    config(['stylizer.job_deadline_seconds' => 300]);
    $this->credits = app(CreditService::class);
    $this->user = User::factory()->create();
    $this->credits->grant($this->user, 10, LedgerReason::Signup);

    $this->make = function (StylizationStatus $status, int $ageSeconds) {
        $stylization = Stylization::factory()->create([
            'user_id' => $this->user->id,
            'style_id' => Style::factory(),
            'status' => $status,
        ]);
        $this->credits->spendForStylization($this->user, 1, $stylization);
        $stylization->forceFill(['created_at' => now()->subSeconds($ageSeconds)])->save();

        return $stylization;
    };
});

it('fails and refunds stale queued and processing previews exactly once', function () {
    $queued = ($this->make)(StylizationStatus::Queued, 500);
    $processing = ($this->make)(StylizationStatus::Processing, 500);

    $this->artisan('creations:sweep')->assertSuccessful();
    $this->artisan('creations:sweep')->assertSuccessful();

    expect($queued->fresh()->status)->toBe(StylizationStatus::Failed)
        ->and($processing->fresh()->status)->toBe(StylizationStatus::Failed)
        ->and($this->credits->balance($this->user))->toBe(10);
});

it('leaves fresh previews and previews waiting for approval alone', function () {
    $fresh = ($this->make)(StylizationStatus::Queued, 100);
    $within = ($this->make)(StylizationStatus::Processing, 400);
    $ready = ($this->make)(StylizationStatus::Ready, 5000);

    $this->artisan('creations:sweep')->assertSuccessful();

    expect($fresh->fresh()->status)->toBe(StylizationStatus::Queued)
        ->and($within->fresh()->status)->toBe(StylizationStatus::Processing)
        ->and($ready->fresh()->status)->toBe(StylizationStatus::Ready)
        ->and($this->credits->balance($this->user))->toBe(7);
});
