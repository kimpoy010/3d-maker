<?php

namespace App\Http\Controllers;

use App\Enums\ClickerStatus;
use App\Models\Clicker;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClickerFileController extends Controller
{
    public function __invoke(Clicker $clicker, string $type): StreamedResponse
    {
        Gate::authorize('view', $clicker);

        $path = match ($type) {
            'artwork' => $clicker->artwork_path,
            'thumbnail' => $clicker->thumbnail_path,
            '3mf' => $clicker->threemf_path,
            'stl' => $clicker->stl_zip_path,
            default => abort(404),
        };

        // The two print files are the paid download.
        $isDownload = in_array($type, ['3mf', 'stl'], true);
        abort_if($isDownload && (! $clicker->isUnlocked() || $clicker->status !== ClickerStatus::Ready), 403, 'Unlock the downloads for this design first.');

        $disk = Storage::disk('local');
        abort_if(! $path || ! $disk->exists($path), 404);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            'Cache-Control' => 'private, no-cache',
        ];

        if ($isDownload) {
            $name = "clicker-{$clicker->id}-{$clicker->created_at->format('Ymd-His')}";

            return $disk->download($path, $type === '3mf' ? "{$name}.3mf" : "{$name}-stl.zip", $headers);
        }

        return $disk->response($path, null, $headers);
    }
}
