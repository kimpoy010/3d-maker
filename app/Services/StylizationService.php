<?php

namespace App\Services;

use App\Enums\StylizationStatus;
use App\Exceptions\DailyLimitReachedException;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\InvalidImageException;
use App\Exceptions\StylizationNotReadyException;
use App\Exceptions\StylizerDisabledException;
use App\Jobs\RestylePhoto;
use App\Models\Creation;
use App\Models\Style;
use App\Models\Stylization;
use App\Models\User;
use App\Services\Credits\CreditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class StylizationService
{
    public function __construct(
        private ImageSanitizer $sanitizer,
        private CreditService $credits,
        private CreationService $creations,
    ) {}

    /**
     * Store a cleaned copy of the photo, charge the restyle fee and queue the restyle.
     *
     * @throws StylizerDisabledException
     * @throws DailyLimitReachedException
     * @throws InvalidImageException
     * @throws InsufficientCreditsException
     */
    public function create(User $user, UploadedFile $photo, Style $style): Stylization
    {
        $this->assertCanStart($user);

        $jpeg = $this->sanitizer->sanitize($photo->getRealPath());
        $path = 'stylizations/'.Str::uuid().'/original.jpg';
        Storage::disk('local')->put($path, $jpeg);

        try {
            $stylization = $this->chargeAndCreate($user, $style, $path);
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);

            throw $e;
        }

        RestylePhoto::dispatch($stylization->id);

        return $stylization;
    }

    /**
     * The kill switch and the daily limit. Runs BEFORE any transaction: a plain SELECT inside
     * the charging transaction would fix its snapshot before the user-row lock is taken.
     *
     * @throws StylizerDisabledException
     * @throws DailyLimitReachedException
     */
    public function assertCanStart(User $user): void
    {
        if (! config('stylizer.enabled')) {
            throw new StylizerDisabledException('Previews are temporarily unavailable.');
        }

        $today = Stylization::where('user_id', $user->id)->where('created_at', '>=', now()->startOfDay())->count();

        if ($today >= (int) config('stylizer.daily_limit')) {
            throw new DailyLimitReachedException('Daily preview limit reached.');
        }
    }

    /** Only an INSERT runs before the charge, which takes the user-row lock. */
    private function chargeAndCreate(User $user, Style $style, string $sourcePath): Stylization
    {
        return DB::transaction(function () use ($user, $style, $sourcePath) {
            $stylization = Stylization::create([
                'user_id' => $user->id,
                'style_id' => $style->id,
                'source_image_path' => $sourcePath,
                'status' => StylizationStatus::Queued,
                'cost_credits' => (int) config('credits.restyle_cost'),
            ]);

            $this->credits->spendForStylization($user, $stylization->cost_credits, $stylization);

            return $stylization;
        });
    }

    /**
     * Turn a ready preview into a 3D creation. Idempotent: approving twice returns the same
     * creation and charges once.
     *
     * Lock order is stylization row, then user row (inside makeCreation). Everything that
     * needs a plain SELECT (the style) is loaded BEFORE the transaction.
     *
     * @throws StylizationNotReadyException
     * @throws InsufficientCreditsException
     */
    public function approve(User $user, Stylization $stylization): Creation
    {
        $style = Style::findOrFail($stylization->style_id);
        $disk = Storage::disk('local');
        $newPath = null;
        $created = false;
        $leftovers = [];

        try {
            $creation = DB::transaction(function () use ($user, $stylization, $style, $disk, &$newPath, &$created, &$leftovers) {
                $locked = Stylization::whereKey($stylization->id)->lockForUpdate()->firstOrFail();

                if ($locked->status === StylizationStatus::Approved && $locked->creation_id) {
                    return Creation::findOrFail($locked->creation_id);
                }

                if ($locked->status !== StylizationStatus::Ready || ! $locked->result_image_path) {
                    throw new StylizationNotReadyException('This preview is not ready to be approved.');
                }

                // A clean, size-capped JPEG copy becomes the creation's own source image.
                $newPath = 'uploads/'.Str::uuid().'.jpg';
                $disk->put($newPath, $this->sanitizer->sanitize($disk->path($locked->result_image_path)));

                $creation = $this->creations->makeCreation($user, $style, $newPath);

                $leftovers = array_filter([$locked->source_image_path, $locked->result_image_path]);
                $locked->update([
                    'status' => StylizationStatus::Approved,
                    'creation_id' => $creation->id,
                    'source_image_path' => null,
                    'result_image_path' => null,
                ]);
                $created = true;

                return $creation;
            });
        } catch (Throwable $e) {
            if ($newPath) {
                $disk->delete($newPath);
            }

            throw $e;
        }

        if ($created) {
            $this->creations->dispatchGeneration($creation);
            $disk->delete($leftovers);
        }

        return $creation;
    }

    /**
     * Start a fresh preview from the same photo and discard this one. Allowed from ready or
     * failed. The new preview takes over the photo file. Charges the restyle fee again.
     *
     * @throws StylizationNotReadyException
     * @throws StylizerDisabledException
     * @throws DailyLimitReachedException
     * @throws InsufficientCreditsException
     */
    public function retry(User $user, Stylization $stylization): Stylization
    {
        $this->assertCanStart($user); // before the transaction, see assertCanStart()

        $resultToDelete = null;

        $new = DB::transaction(function () use ($user, $stylization, &$resultToDelete) {
            $locked = Stylization::whereKey($stylization->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [StylizationStatus::Ready, StylizationStatus::Failed], true) || ! $locked->source_image_path) {
                throw new StylizationNotReadyException('This preview cannot be retried.');
            }

            $new = Stylization::create([
                'user_id' => $user->id,
                'style_id' => $locked->style_id,
                'source_image_path' => $locked->source_image_path,
                'status' => StylizationStatus::Queued,
                'cost_credits' => (int) config('credits.restyle_cost'),
            ]);

            $this->credits->spendForStylization($user, $new->cost_credits, $new);

            $resultToDelete = $locked->result_image_path;
            $locked->update([
                'status' => StylizationStatus::Discarded,
                'source_image_path' => null,
                'result_image_path' => null,
            ]);

            return $new;
        });

        if ($resultToDelete) {
            Storage::disk('local')->delete($resultToDelete);
        }

        RestylePhoto::dispatch($new->id);

        return $new;
    }

    /** queued/processing to processing. Returns whether a row changed. */
    public function markProcessing(int $id): bool
    {
        return Stylization::whereKey($id)
            ->whereIn('status', $this->working())
            ->update(['status' => StylizationStatus::Processing->value, 'updated_at' => now()]) === 1;
    }

    /** queued/processing to ready, recording the result image. Returns whether a row changed. */
    public function markReady(int $id, string $resultPath): bool
    {
        return Stylization::whereKey($id)
            ->whereIn('status', $this->working())
            ->update([
                'status' => StylizationStatus::Ready->value,
                'result_image_path' => $resultPath,
                'error' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * Fail and refund atomically. Only a preview still being made can fail, and the refund
     * shares the transaction: if it throws, the status rolls back. Returns whether this call
     * moved the stylization to failed.
     */
    public function markFailed(int $id, string $message): bool
    {
        $failed = DB::transaction(function () use ($id, $message) {
            $updated = Stylization::whereKey($id)
                ->whereIn('status', $this->working())
                ->update(['status' => StylizationStatus::Failed->value, 'error' => $message, 'updated_at' => now()]);

            if ($updated === 0) {
                return false;
            }

            $this->credits->refundStylization(Stylization::findOrFail($id));

            return true;
        });

        if ($failed) {
            // A crashed run may have stored a result the row never recorded; drop that orphan.
            try {
                Storage::disk('local')->delete("stylizations/{$id}/result.png");
            } catch (Throwable) {
                // best effort: the refund has already committed
            }
        }

        return $failed;
    }

    /**
     * Throw a preview away (customer discard, retry, or retention prune): ready or failed only.
     * Deletes its files. Returns whether it was discarded.
     */
    public function discard(Stylization $stylization): bool
    {
        $discarded = Stylization::whereKey($stylization->id)
            ->whereIn('status', [StylizationStatus::Ready->value, StylizationStatus::Failed->value])
            ->update([
                'status' => StylizationStatus::Discarded->value,
                'source_image_path' => null,
                'result_image_path' => null,
                'updated_at' => now(),
            ]) === 1;

        if ($discarded) {
            Storage::disk('local')->delete(array_filter([$stylization->source_image_path, $stylization->result_image_path]));
        }

        return $discarded;
    }

    /** @return list<string> */
    private function working(): array
    {
        return [StylizationStatus::Queued->value, StylizationStatus::Processing->value];
    }
}
