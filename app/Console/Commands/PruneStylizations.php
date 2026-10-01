<?php

namespace App\Console\Commands;

use App\Enums\StylizationStatus;
use App\Models\Stylization;
use App\Services\StylizationService;
use Illuminate\Console\Command;

class PruneStylizations extends Command
{
    protected $signature = 'stylizations:prune';

    protected $description = 'Discard unapproved previews older than the retention period and delete their photos';

    public function handle(StylizationService $stylizations): int
    {
        $cutoff = now()->subDays((int) config('stylizer.retention_days'));
        $discarded = 0;

        Stylization::query()
            ->whereIn('status', [StylizationStatus::Ready->value, StylizationStatus::Failed->value])
            ->where('created_at', '<', $cutoff)
            ->get()
            ->each(function (Stylization $stylization) use ($stylizations, &$discarded) {
                if ($stylizations->discard($stylization)) {
                    $discarded++;
                }
            });

        $this->info("Discarded {$discarded} old preview(s).");

        return self::SUCCESS;
    }
}
