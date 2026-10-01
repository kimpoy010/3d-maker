<?php

namespace App\Services;

use App\Enums\CreationStatus;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\InvalidImageException;
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
}
