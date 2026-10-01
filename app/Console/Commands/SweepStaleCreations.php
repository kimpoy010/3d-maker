<?php

namespace App\Console\Commands;

use App\Enums\CreationStatus;
use App\Models\Creation;
use App\Services\CreationService;
use Illuminate\Console\Command;

class SweepStaleCreations extends Command
{
    protected $signature = 'creations:sweep';

    protected $description = 'Fail and refund creations stuck in queued/processing past the generation timeout';

    public function handle(CreationService $creations): int
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

        $this->info("Failed {$failed} stale creation(s).");

        return self::SUCCESS;
    }
}
