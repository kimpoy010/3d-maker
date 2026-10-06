<?php

namespace App\Services\Clickers;

use App\Enums\ClickerStatus;
use App\Exceptions\ClickerLimitReachedException;
use App\Exceptions\ClickerStateException;
use App\Exceptions\InsufficientCreditsException;
use App\Jobs\BuildClickerFiles;
use App\Models\Clicker;
use App\Models\ClickerBody;
use App\Models\User;
use App\Services\Credits\CreditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ClickerService
{
    public function __construct(private CreditService $credits) {}

    /**
     * @param  array<string, mixed>  $settings
     *
     * @throws ClickerLimitReachedException
     */
    public function create(User $user, ClickerBody $body, string $name, array $settings, string $artworkPng, ?string $thumbnailJpeg): Clicker
    {
        $clicker = DB::transaction(function () use ($user, $body, $name, $settings) {
            $this->assertRoom($user);

            return Clicker::create([
                'user_id' => $user->id,
                'clicker_body_id' => $body->id,
                'name' => $name,
                'settings' => $settings,
                'status' => ClickerStatus::Draft,
            ]);
        });

        try {
            $this->storeFiles($clicker, $artworkPng, $thumbnailJpeg);
        } catch (Throwable $e) {
            Storage::disk('local')->deleteDirectory($clicker->directory());
            $clicker->delete();

            throw $e;
        }

        return $clicker->fresh();
    }

    /**
     * @param  array<string, mixed>  $settings
     *
     * @throws ClickerStateException when the design is unlocked or building
     */
    public function update(Clicker $clicker, ClickerBody $body, string $name, array $settings, ?string $artworkPng, ?string $thumbnailJpeg): Clicker
    {
        // The file writes happen inside the lock, after the state check, so an unlock cannot slip in between.
        DB::transaction(function () use ($clicker, $body, $name, $settings, $artworkPng, $thumbnailJpeg) {
            $locked = Clicker::whereKey($clicker->id)->lockForUpdate()->firstOrFail();

            if ($locked->isUnlocked() || $locked->status === ClickerStatus::Building) {
                throw new ClickerStateException('This design is locked. Duplicate it to make changes.');
            }

            $locked->update(['clicker_body_id' => $body->id, 'name' => $name, 'settings' => $settings]);
            $this->storeFiles($locked, $artworkPng, $thumbnailJpeg);
        });

        return $clicker->fresh();
    }

    /** @throws ClickerLimitReachedException */
    public function duplicate(User $user, Clicker $source): Clicker
    {
        $copy = DB::transaction(function () use ($user, $source) {
            $this->assertRoom($user);

            return Clicker::create([
                'user_id' => $user->id,
                'clicker_body_id' => $source->clicker_body_id,
                'name' => Str::limit('Copy of '.$source->name, 60, ''),
                'settings' => $source->settings,
                'status' => ClickerStatus::Draft,
            ]);
        });

        $disk = Storage::disk('local');

        try {
            $updates = [];
            foreach (['artwork_path' => 'artwork.png', 'thumbnail_path' => 'thumbnail.jpg'] as $column => $file) {
                if ($source->{$column} && $disk->exists($source->{$column})) {
                    $target = "{$copy->directory()}/{$file}";
                    $disk->copy($source->{$column}, $target);
                    $updates[$column] = $target;
                }
            }
            if ($updates) {
                $copy->update($updates);
            }
        } catch (Throwable $e) {
            $disk->deleteDirectory($copy->directory());
            $copy->delete();

            throw $e;
        }

        return $copy->fresh();
    }

    /**
     * Pay once to build and download the print files. Idempotent: unlocking an already unlocked design
     * charges nothing. Locks the clicker row first, then the user row (inside the charge); the build is
     * dispatched only after the transaction commits, and the sweeper refunds one that never starts.
     *
     * @throws ClickerStateException
     * @throws InsufficientCreditsException
     */
    public function unlock(User $user, Clicker $clicker): Clicker
    {
        $dispatch = false;

        $result = DB::transaction(function () use ($user, $clicker, &$dispatch) {
            $locked = Clicker::whereKey($clicker->id)->lockForUpdate()->firstOrFail();

            if ($locked->isUnlocked()) {
                return $locked;
            }

            if (! $locked->artwork_path) {
                throw new ClickerStateException('Add artwork before unlocking the downloads.');
            }

            $attempt = $locked->unlock_attempts + 1;
            $this->credits->spendForClicker($user, (int) config('credits.clicker_cost'), $locked, $attempt);

            $locked->update([
                'unlock_attempts' => $attempt,
                'downloads_unlocked_at' => now(),
                'status' => ClickerStatus::Building,
                'build_started_at' => now(),
                'build_error' => null,
                'threemf_path' => null,
                'stl_zip_path' => null,
            ]);
            $dispatch = true;

            return $locked;
        });

        if ($dispatch) {
            BuildClickerFiles::dispatch($result->id, $result->unlock_attempts)->afterCommit();
        }

        return $result;
    }

    /** Building to ready. Returns false when the design is no longer building. */
    public function markReady(int $clickerId, string $threemfPath, string $stlZipPath, ?int $attempt = null): bool
    {
        return Clicker::whereKey($clickerId)
            ->where('status', ClickerStatus::Building->value)
            ->when($attempt !== null, fn ($query) => $query->where('unlock_attempts', $attempt))
            ->update([
                'status' => ClickerStatus::Ready->value,
                'threemf_path' => $threemfPath,
                'stl_zip_path' => $stlZipPath,
                'build_error' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * Building to failed, refunding the latest attempt in the same transaction, and clearing the unlock so
     * the user can pay for a new attempt. Returns false when the design is no longer building.
     */
    public function markFailed(int $clickerId, string $message, ?int $attempt = null): bool
    {
        return DB::transaction(function () use ($clickerId, $message, $attempt) {
            $clicker = Clicker::whereKey($clickerId)->lockForUpdate()->first();

            if (! $clicker || $clicker->status !== ClickerStatus::Building || ($attempt !== null && $clicker->unlock_attempts !== $attempt)) {
                return false;
            }

            $clicker->update([
                'status' => ClickerStatus::Failed,
                'build_error' => $message,
                'downloads_unlocked_at' => null,
            ]);
            $this->credits->refundClicker($clicker, $clicker->unlock_attempts);

            return true;
        });
    }

    public function destroy(Clicker $clicker): void
    {
        DB::transaction(function () use ($clicker) {
            $locked = Clicker::whereKey($clicker->id)->lockForUpdate()->first();

            if (! $locked) {
                return;
            }

            if ($locked->status === ClickerStatus::Building) {
                throw new ClickerStateException('This design is being built and cannot be deleted yet.');
            }

            $locked->delete();
        });

        Storage::disk('local')->deleteDirectory($clicker->directory());
    }

    /** Lock the user row so two parallel saves cannot both slip under the limit. */
    private function assertRoom(User $user): void
    {
        User::query()->whereKey($user->id)->lockForUpdate()->first();

        if (Clicker::where('user_id', $user->id)->count() >= (int) config('clicker.max_per_user')) {
            throw new ClickerLimitReachedException;
        }
    }

    private function storeFiles(Clicker $clicker, ?string $artworkPng, ?string $thumbnailJpeg): void
    {
        $disk = Storage::disk('local');
        $updates = [];

        if ($artworkPng !== null) {
            $updates['artwork_path'] = "{$clicker->directory()}/artwork.png";
            $disk->put($updates['artwork_path'], $artworkPng);
        }

        if ($thumbnailJpeg !== null) {
            $updates['thumbnail_path'] = "{$clicker->directory()}/thumbnail.jpg";
            $disk->put($updates['thumbnail_path'], $thumbnailJpeg);
        }

        if ($updates) {
            $clicker->update($updates);
        }
    }
}
