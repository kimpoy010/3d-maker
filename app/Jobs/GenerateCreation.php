<?php

namespace App\Jobs;

use App\Enums\CreationStatus;
use App\Models\Creation;
use App\Services\CreationService;
use App\Services\Credits\CreditService;
use App\Services\ModelProviders\ModelProvider;
use App\Services\ModelProviders\PermanentProviderException;
use App\Services\ModelProviders\ProviderState;
use App\Services\ModelProviders\TransientProviderException;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateCreation implements ShouldQueue
{
    use Queueable;

    /** Seconds to wait before retrying after a transient provider error. */
    private const TRANSIENT_RETRY_SECONDS = 10;

    private const FINISHED = ['succeeded', 'failed'];

    /** Give up after this many unexpected (non-provider) exceptions. */
    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [5, 15, 30];

    /**
     * Hard per-attempt limit for the worker. Must stay below the overlap lock's
     * expiry, which must stay below the queue connection's retry_after (90s).
     */
    public int $timeout = 60;

    public function __construct(public int $creationId) {}

    /**
     * One run per creation at a time. The lock expires (80s) after the worker's
     * timeout (60s) but before retry_after (90s), so a crashed run frees the lock
     * before the queue hands the job to another worker.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->creationId))->releaseAfter(5)->expireAfter(80)];
    }

    /**
     * Polling re-releases the job, which counts as an attempt, so the retry window
     * (not a fixed attempt count) bounds the job. Our own deadline check in handle()
     * fires first; this is the safety net.
     */
    public function retryUntil(): CarbonInterface
    {
        return now()->addSeconds((int) config('models.timeout_seconds') + 120);
    }

    public function handle(ModelProvider $provider, CreditService $credits): void
    {
        $creation = Creation::with('style')->find($this->creationId);

        if (! $creation || $creation->status->isFinished()) {
            return;
        }

        if ($creation->created_at->addSeconds((int) config('models.timeout_seconds'))->isPast()) {
            $this->markFailed($creation, 'Generation timed out.');

            return;
        }

        try {
            if (! $creation->provider_job_id) {
                $creation->provider_job_id = $provider->start(
                    Storage::disk('local')->path($creation->source_image_path),
                    $creation->style,
                );
                $creation->status = CreationStatus::Processing;
                $creation->save();
            }

            $result = $provider->status($creation->provider_job_id);

            match ($result->state) {
                ProviderState::Pending, ProviderState::Running => $this->stillWorking($creation, $result->progress),
                ProviderState::Failed => $this->markFailed($creation, $result->error ?? 'The model could not be generated.', $credits),
                ProviderState::Succeeded => $this->succeed($creation, $provider, $result->modelUrl, $result->thumbnailUrl, $result->printModelUrl),
            };
        } catch (TransientProviderException) {
            $this->release(self::TRANSIENT_RETRY_SECONDS);
        } catch (PermanentProviderException $e) {
            $this->markFailed($creation, $e->getMessage());
        }
    }

    /** Called by the queue when the job exhausts its retries or throws unexpectedly. */
    public function failed(?Throwable $exception): void
    {
        $creation = Creation::find($this->creationId);

        if ($creation && ! $creation->status->isFinished()) {
            $this->markFailed($creation, 'Generation failed unexpectedly.');
        }
    }

    private function stillWorking(Creation $creation, ?int $progress): void
    {
        $creation->forceFill(['status' => CreationStatus::Processing, 'progress' => $progress])->save();
        $this->release((int) config('models.poll_seconds'));
    }

    private function succeed(Creation $creation, ModelProvider $provider, ?string $modelUrl, ?string $thumbnailUrl, ?string $printUrl): void
    {
        if (! $modelUrl) {
            throw new PermanentProviderException('The provider finished without a model.');
        }

        $disk = Storage::disk('local');
        $modelPath = "creations/{$creation->id}/model.glb";
        $thumbPath = null;

        $disk->put($modelPath, $provider->download($modelUrl));

        if ($thumbnailUrl) {
            $thumbPath = "creations/{$creation->id}/thumbnail.png";
            $disk->put($thumbPath, $provider->download($thumbnailUrl));
        }

        $printPath = null;

        if (! $printUrl) {
            Log::warning('Provider finished without a print model', ['creation_id' => $creation->id]);
        } else {
            $printPath = "creations/{$creation->id}/print.stl";
            $disk->put($printPath, $provider->download($printUrl));
        }

        $updated = Creation::whereKey($creation->id)
            ->whereNotIn('status', self::FINISHED)
            ->update([
                'status' => CreationStatus::Succeeded->value,
                'model_path' => $modelPath,
                'thumbnail_path' => $thumbPath,
                'print_model_path' => $printPath,
                'progress' => 100,
                'error' => null,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            // Another run already finished this creation. If it failed (and was refunded),
            // drop what we stored; if it succeeded, the identical files are its own.
            if (Creation::whereKey($creation->id)->first()?->status === CreationStatus::Failed) {
                $disk->delete(array_filter([$modelPath, $thumbPath, $printPath]));
            }

            return;
        }

        $creation->refresh();
    }

    /** Fail and refund through the shared service, then sync the caller's model. */
    private function markFailed(Creation $creation, string $message): void
    {
        app(CreationService::class)->markFailed($creation->id, $message);

        $creation->refresh();
    }
}
