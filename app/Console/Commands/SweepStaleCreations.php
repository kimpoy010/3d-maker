<?php

namespace App\Console\Commands;

use App\Enums\ClickerStatus;
use App\Enums\CreationStatus;
use App\Enums\StylizationStatus;
use App\Models\Clicker;
use App\Models\Creation;
use App\Models\Stylization;
use App\Services\Clickers\ClickerService;
use App\Services\CreationService;
use App\Services\StylizationService;
use Illuminate\Console\Command;

class SweepStaleCreations extends Command
{
    protected $signature = 'creations:sweep';

    protected $description = 'Fail and refund creations, previews and clicker builds stuck past their timeout';

    public function handle(CreationService $creations, StylizationService $stylizations, ClickerService $clickers): int
    {
        $failedCreations = $this->sweepCreations($creations);
        $failedPreviews = $this->sweepStylizations($stylizations);
        $failedClickers = $this->sweepClickers($clickers);

        $this->info("Failed {$failedCreations} stale creation(s), {$failedPreviews} stale preview(s) and {$failedClickers} stale clicker build(s).");

        return self::SUCCESS;
    }

    private function sweepCreations(CreationService $creations): int
    {
        $cutoff = now()->subSeconds((int) config('models.timeout_seconds') + 120);
        $failed = 0;

        Creation::query()
            ->whereIn('status', [CreationStatus::Queued->value, CreationStatus::Processing->value])
            ->where('created_at', '<', $cutoff)
            ->pluck('id')
            ->each(function (int $id) use ($creations, &$failed) {
                if ($creations->markFailed($id, 'Generation timed out.')) {
                    $failed++;
                }
            });

        return $failed;
    }

    private function sweepStylizations(StylizationService $stylizations): int
    {
        $cutoff = now()->subSeconds((int) config('stylizer.job_deadline_seconds') + 120);
        $failed = 0;

        Stylization::query()
            ->whereIn('status', [StylizationStatus::Queued->value, StylizationStatus::Processing->value])
            ->where('created_at', '<', $cutoff)
            ->pluck('id')
            ->each(function (int $id) use ($stylizations, &$failed) {
                if ($stylizations->markFailed($id, 'The preview timed out.')) {
                    $failed++;
                }
            });

        return $failed;
    }

    private function sweepClickers(ClickerService $clickers): int
    {
        $cutoff = now()->subSeconds((int) config('clicker.build_deadline_seconds') + 120);
        $failed = 0;

        Clicker::query()
            ->where('status', ClickerStatus::Building->value)
            ->where('build_started_at', '<', $cutoff)
            ->pluck('id')
            ->each(function (int $id) use ($clickers, &$failed) {
                if ($clickers->markFailed($id, 'The files timed out.')) {
                    $failed++;
                }
            });

        return $failed;
    }
}
