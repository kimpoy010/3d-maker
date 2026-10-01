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
            'thumbnail' => $creation->thumbnail_path,
        };

        $disk = Storage::disk('local');
        abort_if(! $path || ! $disk->exists($path), 404);

        if ($type === 'model' && $request->boolean('download')) {
            return $disk->download($path, "creation-{$creation->id}.glb");
        }

        return $disk->response($path, null, ['Cache-Control' => 'private, max-age=3600']);
    }
}
