<?php

use App\Enums\ClickerStatus;
use App\Enums\LedgerReason;
use App\Models\Clicker;
use App\Models\User;
use App\Services\Clickers\ClickerService;
use App\Services\Credits\CreditService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    config(['credits.clicker_cost' => 50, 'clicker.build_deadline_seconds' => 300]);

    $this->credits = app(CreditService::class);
    $this->user = User::factory()->create();
    $this->credits->grant($this->user, 120, LedgerReason::Signup);

    $this->building = function (int $startedSecondsAgo): Clicker {
        $clicker = Clicker::factory()->create(['user_id' => $this->user->id, 'artwork_path' => 'clickers/x/artwork.png']);
        app(ClickerService::class)->unlock($this->user, $clicker);
        $clicker->update(['build_started_at' => now()->subSeconds($startedSecondsAgo)]);

        return $clicker;
    };
});

it('fails and refunds a build stuck past its deadline, exactly once', function () {
    $stale = ($this->building)(5000);

    $this->artisan('creations:sweep')->assertSuccessful();
    $this->artisan('creations:sweep')->assertSuccessful();

    expect($stale->fresh()->status)->toBe(ClickerStatus::Failed)
        ->and($stale->fresh()->build_error)->toBe('The files timed out.')
        ->and($this->credits->balance($this->user))->toBe(120);
});

it('leaves a recent build alone', function () {
    $recent = ($this->building)(30);

    $this->artisan('creations:sweep')->assertSuccessful();

    expect($recent->fresh()->status)->toBe(ClickerStatus::Building)
        ->and($this->credits->balance($this->user))->toBe(70);
});
