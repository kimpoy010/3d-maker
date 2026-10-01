<?php

namespace App\Jobs;

use App\Models\Stylization;
use App\Services\ModelProviders\PermanentProviderException;
use App\Services\ModelProviders\TransientProviderException;
use App\Services\StylizationService;
use App\Services\Stylizers\ImageStylizer;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RestylePhoto implements ShouldQueue
{
    use Queueable;

    /** Seconds to wait before retrying after a transient provider error. */
    private const TRANSIENT_RETRY_SECONDS = 10;

    /** Give up after this many unexpected (non-provider) exceptions. */
    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [5, 15, 30];

    /**
     * Hard per-attempt limit for the worker. The provider HTTP call has its own, shorter
     * timeout. Must stay below the lock expiry (240s), which must stay below the queue
     * connection's retry_after (300s).
     */
    public int $timeout = 150;

    public function __construct(public int $stylizationId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->stylizationId))->releaseAfter(5)->expireAfter(240)];
    }

    public function retryUntil(): CarbonInterface
    {
        return now()->addSeconds((int) config('stylizer.job_deadline_seconds') + 120);
    }

    public function handle(ImageStylizer $stylizer, StylizationService $stylizations): void
    {
        $stylization = Stylization::with('style')->find($this->stylizationId);

        if (! $stylization || ! $stylization->status->isWorking()) {
            return;
        }

        if ($stylization->created_at->addSeconds((int) config('stylizer.job_deadline_seconds'))->isPast()) {
            $stylizations->markFailed($stylization->id, 'The preview timed out.');

            return;
        }

        $stylizations->markProcessing($stylization->id);
        $disk = Storage::disk('local');

        try {
            $png = $stylizer->stylize($disk->path($stylization->source_image_path), $stylization->style);
        } catch (TransientProviderException) {
            $this->release(self::TRANSIENT_RETRY_SECONDS);

            return;
        } catch (PermanentProviderException $e) {
            $stylizations->markFailed($stylization->id, $e->getMessage());

            return;
        }

        $resultPath = "stylizations/{$stylization->id}/result.png";
        $disk->put($resultPath, $png);

        // Lost the race (swept, or already finished): drop what we stored.
        if (! $stylizations->markReady($stylization->id, $resultPath)) {
            $disk->delete($resultPath);
        }
    }

    /** Called by the queue when the job exhausts its retries or throws unexpectedly. */
    public function failed(?Throwable $exception): void
    {
        app(StylizationService::class)->markFailed($this->stylizationId, 'The preview failed unexpectedly.');
    }
}
