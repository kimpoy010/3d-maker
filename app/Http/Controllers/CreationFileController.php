<?php

namespace App\Http\Controllers;

use App\Models\Creation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CreationFileController extends Controller
{
    public function __invoke(Request $request, Creation $creation, string $type): StreamedResponse
    {
        Gate::authorize('view', $creation);

        $path = match ($type) {
            'source' => $creation->source_image_path,
            'model' => $creation->model_path,
            'print' => $creation->print_model_path,
            'thumbnail' => $creation->thumbnail_path,
        };

        $disk = Storage::disk('local');
        abort_if(! $path || ! $disk->exists($path), 404);

        // creation-29-20261003-172648.glb: the id plus when the model was made (app timezone).
        $name = "creation-{$creation->id}-{$creation->created_at->format('Ymd-His')}";

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
        ];

        if ($type === 'model' && $request->boolean('download')) {
            return $disk->download($path, "{$name}.glb", $headers);
        }

        if ($type === 'print') {
            // The STL is only ever a download, never rendered in the page.
            return $disk->download($path, "{$name}.stl", $headers + ['Cache-Control' => 'private, no-cache']);
        }

        // The photo derivatives are personal: the browser must revalidate so a deleted
        // creation's image cannot linger. The large GLB is not personal and stays cacheable.
        $headers['Cache-Control'] = $type === 'model' ? 'private, max-age=3600' : 'private, no-cache';

        return $disk->response($path, null, $headers);
    }
}
