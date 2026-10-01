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
        return response(MockAssets::glb(Subject::Person), 200, [
            'Content-Type' => 'model/gltf-binary',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
