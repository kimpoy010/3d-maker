<?php

namespace App\Services;

use App\Enums\CreationStatus;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\StylizationNotReadyException;
use App\Jobs\GenerateCreation;
use App\Models\Creation;
use App\Models\Style;
use App\Models\User;
use App\Services\Credits\CreditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class CreationService
{
    public function __construct(
        private CreditService $credits,
    ) {}

    /**
     * Insert a queued creation and charge its 3D price. Does NOT dispatch: callers that run
     * inside a larger transaction must dispatch only after it commits
     * ({@see dispatchGeneration()}). Only an INSERT runs before the charge, which takes the
     * user-row lock, so the transaction snapshot is taken after the lock.
     *
     * @throws InsufficientCreditsException
     */
    public function makeCreation(User $user, Style $style, string $storedImagePath): Creation
    {
        return DB::transaction(function () use ($user, $style, $storedImagePath) {
            $creation = Creation::create([
                'user_id' => $user->id,
                'style_id' => $style->id,
                'source_image_path' => $storedImagePath,
                'status' => CreationStatus::Queued,
                'cost_credits' => $style->credit_cost,
            ]);

            $this->credits->spend($user, $style->credit_cost, $creation);

            return $creation;
        });
    }

    /**
     * Pay once to download the GLB and STL of a finished creation. Idempotent: unlocking again
     * charges nothing. The creation row is locked first, then the user row (inside the charge).
     *
     * @throws StylizationNotReadyException when the creation has no finished model
     * @throws InsufficientCreditsException
     */
    public function unlockDownloads(User $user, Creation $creation): Creation
    {
        return DB::transaction(function () use ($user, $creation) {
            $locked = Creation::whereKey($creation->id)->lockForUpdate()->firstOrFail();

            if ($locked->downloads_unlocked_at) {
                return $locked;
            }

            if ($locked->status !== CreationStatus::Succeeded || ! $locked->model_path) {
                throw new StylizationNotReadyException('Only a finished model can be unlocked.');
            }

            $this->credits->spendForDownload($user, (int) config('credits.download_cost'), $locked);
            $locked->update(['downloads_unlocked_at' => now()]);

            return $locked;
        });
    }

    public function dispatchGeneration(Creation $creation): void
    {
        GenerateCreation::dispatch($creation->id);
    }

    /** makeCreation in its own transaction, then dispatch. */
    public function startFromImage(User $user, Style $style, string $storedImagePath): Creation
    {
        $creation = $this->makeCreation($user, $style, $storedImagePath);

        $this->dispatchGeneration($creation);

        return $creation;
    }

    /**
     * Re-run the 3D build for a failed creation from a copy of its stored image.
     *
     * @throws StylizationNotReadyException when the creation is not failed or its image is gone
     * @throws InsufficientCreditsException
     */
    public function retryFailed(User $user, Creation $creation): Creation
    {
        $disk = Storage::disk('local');

        if ($creation->status !== CreationStatus::Failed || ! $creation->source_image_path || ! $disk->exists($creation->source_image_path)) {
            throw new StylizationNotReadyException('Only a failed creation with its image can be retried.');
        }

        $style = Style::findOrFail($creation->style_id);
        $copy = 'uploads/'.Str::uuid().'.jpg';
        $disk->copy($creation->source_image_path, $copy);

        // Only a failed makeCreation may delete the copy: once it commits, the new creation
        // points at the file, so a dispatch failure must leave it for the sweeper's refund.
        try {
            $creation = $this->makeCreation($user, $style, $copy);
        } catch (Throwable $e) {
            $disk->delete($copy);

            throw $e;
        }

        $this->dispatchGeneration($creation);

        return $creation;
    }

    /**
     * Fail and refund atomically. Conditional on the creation not being finished, and the
     * refund shares the transaction: if it throws, the status rolls back. Returns whether
     * this call moved the creation to failed.
     */
    public function markFailed(int $creationId, string $message): bool
    {
        return DB::transaction(function () use ($creationId, $message) {
            $updated = Creation::whereKey($creationId)
                ->whereNotIn('status', [CreationStatus::Succeeded->value, CreationStatus::Failed->value])
                ->update(['status' => CreationStatus::Failed->value, 'error' => $message, 'updated_at' => now()]);

            if ($updated === 0) {
                return false;
            }

            $this->credits->refund(Creation::findOrFail($creationId));

            return true;
        });
    }
}
