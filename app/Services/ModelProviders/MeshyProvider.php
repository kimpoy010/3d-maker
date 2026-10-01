<?php

namespace App\Services\ModelProviders;

use App\Models\Style;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class MeshyProvider implements ModelProvider
{
    private const BASE = 'https://api.meshy.ai/openapi/v1';

    public const BUILD_FAILED = 'The 3D model could not be built from this image.';

    public const UNAVAILABLE = '3D building is temporarily unavailable. Please try again later.';

    public function start(string $imagePath, Style $style): string
    {
        $bytes = is_file($imagePath) ? file_get_contents($imagePath) : false;

        if ($bytes === false) {
            throw new PermanentProviderException('We could not read the image.');
        }

        $payload = [
            // The approved image is always our own re-encoded JPEG.
            'image_url' => 'data:image/jpeg;base64,'.base64_encode($bytes),
            'should_texture' => true,
            'enable_pbr' => false,
            'should_remesh' => true,
            'target_polycount' => (int) ($style->provider_params['target_faces'] ?? config('models.meshy.default_polycount')),
            'target_formats' => ['glb', 'stl'],
        ];

        if ($model = config('services.meshy.model')) {
            $payload['ai_model'] = $model;
        }

        $response = $this->send(fn (PendingRequest $http) => $http->post(self::BASE.'/image-to-3d', $payload));
        $this->failOnError($response);

        $id = $response->json('result');

        if (! is_string($id) || $id === '') {
            Log::warning('Meshy did not return a task id.', ['status' => $response->status()]);

            throw new PermanentProviderException(self::BUILD_FAILED);
        }

        return $id;
    }

    public function status(string $providerJobId): ProviderResult
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get(self::BASE.'/image-to-3d/'.rawurlencode($providerJobId)));

        if ($response->status() === 404) {
            return new ProviderResult(ProviderState::Failed, error: self::BUILD_FAILED);
        }

        $this->failOnError($response);

        $progress = $response->json('progress');
        $progress = is_numeric($progress) ? (int) $progress : null;

        return match ($response->json('status')) {
            'SUCCEEDED' => new ProviderResult(
                ProviderState::Succeeded,
                modelUrl: $response->json('model_urls.glb'),
                thumbnailUrl: $response->json('thumbnail_url'),
                progress: 100,
                printModelUrl: $response->json('model_urls.stl'),
            ),
            'FAILED', 'CANCELED' => $this->failed($providerJobId, $response),
            'IN_PROGRESS' => new ProviderResult(ProviderState::Running, progress: $progress),
            default => new ProviderResult(ProviderState::Pending, progress: $progress),
        };
    }

    /** Meshy asset links expire, so the caller always downloads. Only Meshy hosts over https. */
    public function download(string $url): string
    {
        if (! $this->isMeshyAssetUrl($url)) {
            throw new PermanentProviderException('Refusing to download from an unexpected address.');
        }

        try {
            $response = Http::connectTimeout(10)->timeout((int) config('models.meshy.timeout_seconds'))->get($url);
        } catch (ConnectionException $e) {
            throw new TransientProviderException('The 3D service could not be reached.', 0, $e);
        }

        if ($response->successful()) {
            return $response->body();
        }

        if ($response->status() >= 500 || $response->status() === 429 || $response->status() === 408) {
            throw new TransientProviderException("The 3D service is busy (HTTP {$response->status()}).");
        }

        throw new PermanentProviderException(self::BUILD_FAILED);
    }

    private function isMeshyAssetUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');

        return ($parts['scheme'] ?? '') === 'https'
            && ($host === 'meshy.ai' || str_ends_with($host, '.meshy.ai'));
    }

    private function failed(string $taskId, Response $response): ProviderResult
    {
        // The provider's own wording can be technical; log it for the operator, show a plain line.
        Log::warning('Meshy task failed.', [
            'task' => $taskId,
            'status' => $response->json('status'),
            'message' => $response->json('task_error.message'),
        ]);

        return new ProviderResult(ProviderState::Failed, error: self::BUILD_FAILED);
    }

    /** @param callable(PendingRequest): Response $call */
    private function send(callable $call): Response
    {
        $key = config('services.meshy.key');

        if (! $key) {
            Log::critical('Meshy API key is not configured; 3D building is unavailable.');

            throw new PermanentProviderException(self::UNAVAILABLE);
        }

        try {
            return $call(Http::withToken($key)->connectTimeout(10)->timeout((int) config('models.meshy.timeout_seconds')));
        } catch (ConnectionException $e) {
            throw new TransientProviderException('The 3D service could not be reached.', 0, $e);
        }
    }

    private function failOnError(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();

        // Our account is the problem (bad key, out of credits, no access): refund the customer,
        // tell the operator. Only the status is logged, never bodies or keys.
        if (in_array($status, [401, 402, 403], true)) {
            Log::critical('Meshy account problem: 3D builds are failing.', ['status' => $status]);

            throw new PermanentProviderException(self::UNAVAILABLE);
        }

        if ($status === 429 || $status === 408 || $status >= 500) {
            throw new TransientProviderException("The 3D service is busy (HTTP {$status}).");
        }

        Log::warning('Meshy rejected a request.', ['status' => $status]);

        throw new PermanentProviderException(self::BUILD_FAILED);
    }
}
