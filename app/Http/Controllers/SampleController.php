<?php

namespace App\Http\Controllers;

use App\Enums\Subject;
use App\Services\ModelProviders\MockAssets;
use Illuminate\Http\Response;

/** Public demo model for the landing page viewer. */
class SampleController extends Controller
{
    public function __invoke(): Response
    {
        $file = resource_path('samples/demo.glb');

        // The shipped sample is a real Meshy build; the generated placeholder only covers a missing file.
        $glb = is_file($file) ? file_get_contents($file) : MockAssets::glb(Subject::Person);

        return response($glb, 200, [
            'Content-Type' => 'model/gltf-binary',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
