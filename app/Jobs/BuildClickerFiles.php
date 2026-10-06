<?php

namespace App\Jobs;

use App\Enums\ClickerStatus;
use App\Models\Clicker;
use App\Services\Clickers\ClickerBuilder;
use App\Services\Clickers\ClickerBuildException;
use App\Services\Clickers\ClickerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use Throwable;

class BuildClickerFiles implements ShouldQueue
{
    use Queueable;

    /**
     * Worker limit. It must stay below the overlap lock's expiry (200 s), which must stay below the
     * queue connection's retry_after (300 s), and above the build process's own timeout (90 s).
     */
    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public int $clickerId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->clickerId))->dontRelease()->expireAfter(200)];
    }

    public function handle(ClickerBuilder $builder, ClickerService $clickers): void
    {
        $clicker = Clicker::with('body')->find($this->clickerId);

        if (! $clicker || $clicker->status !== ClickerStatus::Building) {
            return;
        }

        try {
            $paths = $builder->build($clicker);
        } catch (ClickerBuildException $e) {
            $clickers->markFailed($this->clickerId, $e->getMessage());

            return;
        }

        // If the design stopped building meanwhile (for example the sweeper failed it), drop the outputs.
        if (! $clickers->markReady($this->clickerId, $paths['threemf'], $paths['stl_zip'])) {
            Storage::disk('local')->delete([$paths['threemf'], $paths['stl_zip']]);
        }
    }

    /** Called by the queue when the job crashes or times out. */
    public function failed(?Throwable $exception): void
    {
        app(ClickerService::class)->markFailed($this->clickerId, 'We could not build your files.');
    }
}
