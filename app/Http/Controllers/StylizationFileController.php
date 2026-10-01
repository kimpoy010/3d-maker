<?php

namespace App\Http\Controllers;

use App\Models\Stylization;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StylizationFileController extends Controller
{
    public function __invoke(Stylization $stylization, string $type): StreamedResponse
    {
        Gate::authorize('view', $stylization);

        $path = match ($type) {
            'original' => $stylization->source_image_path,
            'result' => $stylization->result_image_path,
        };

        $disk = Storage::disk('local');
        abort_if(! $path || ! $disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'private, no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
        ]);
    }
}
