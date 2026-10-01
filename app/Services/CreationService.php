<?php

namespace App\Services;

use App\Enums\CreationStatus;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\InvalidImageException;
use App\Exceptions\StylizationNotReadyException;
use App\Jobs\GenerateCreation;
use App\Models\Creation;
use App\Models\Style;
use App\Models\User;
use App\Services\Credits\CreditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class CreationService
{
    public function __construct(
        private ImageSanitizer $sanitizer,
        private CreditService $credits,
    ) {}

    /**
     * Store a cleaned copy of the photo, charge the user, and queue generation.
     *
     * @throws InvalidImageException
     * @throws InsufficientCreditsException
     */
    public function submit(User $user, UploadedFile $photo, Style $style): Creation
    {
        $jpeg = $this->sanitizer->sanitize($photo->getRealPath());
        $path = 'uploads/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, $jpeg);

        try {
            // Only an INSERT runs before spend(): spend() locks the user row, and the
            // transaction's snapshot must be taken after that lock (REPEATABLE READ).
            $creation = DB::transaction(function () use ($user, $style, $path) {
                $creation = Creation::create([
                    'user_id' => $user->id,
                    'style_id' => $style->id,
                    'source_image_path' => $path,
                    'status' => CreationStatus::Queued,
                    'cost_credits' => $style->credit_cost,
                ]);

                $this->credits->spend($user, $style->credit_cost, $creation);

                return $creation;
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);

            throw $e;
        }

        GenerateCreation::dispatch($creation->id);

        return $creation;
    }

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

        try {
            return $this->startFromImage($user, $style, $copy);
        } catch (Throwable $e) {
            $disk->delete($copy);

            throw $e;
        }
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
