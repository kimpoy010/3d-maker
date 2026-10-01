<?php

use App\Enums\StylizationStatus;
use App\Models\Stylization;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config(['stylizer.retention_days' => 7]);

    $this->make = function (StylizationStatus $status, int $ageDays) {
        $stylization = Stylization::factory()->create([
            'status' => $status,
            'source_image_path' => 'stylizations/p/original.jpg',
            'result_image_path' => 'stylizations/p/result.png',
        ]);
        $stylization->forceFill(['created_at' => now()->subDays($ageDays)])->save();

        return $stylization;
    };
    Storage::disk('local')->put('stylizations/p/original.jpg', 'o');
    Storage::disk('local')->put('stylizations/p/result.png', 'r');
});

it('discards old ready and failed previews and deletes their files', function () {
    $ready = ($this->make)(StylizationStatus::Ready, 8);
    $failed = ($this->make)(StylizationStatus::Failed, 9);

    $this->artisan('stylizations:prune')->assertSuccessful();

    expect($ready->fresh()->status)->toBe(StylizationStatus::Discarded)
        ->and($ready->fresh()->source_image_path)->toBeNull()
        ->and($failed->fresh()->status)->toBe(StylizationStatus::Discarded);
    Storage::disk('local')->assertMissing('stylizations/p/original.jpg');
});

it('keeps recent, approved and working previews', function () {
    $recent = ($this->make)(StylizationStatus::Ready, 2);
    $approved = ($this->make)(StylizationStatus::Approved, 30);
    $working = ($this->make)(StylizationStatus::Processing, 30);

    $this->artisan('stylizations:prune')->assertSuccessful();

    expect($recent->fresh()->status)->toBe(StylizationStatus::Ready)
        ->and($approved->fresh()->status)->toBe(StylizationStatus::Approved)
        ->and($working->fresh()->status)->toBe(StylizationStatus::Processing);
});
