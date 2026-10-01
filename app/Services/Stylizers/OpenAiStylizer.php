<?php

namespace App\Services\Stylizers;

use App\Models\Style;
use App\Services\ModelProviders\PermanentProviderException;
use App\Services\ModelProviders\TransientProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class OpenAiStylizer implements ImageStylizer
{
    private const URL = 'https://api.openai.com/v1/images/edits';

    /** Customer-facing messages: plain, and never reveal our account or the provider's wording. */
    public const REFUSED = "We can't process this photo. Please try a different one.";

    public const FAILED = "We couldn't process this photo.";

    public const UNAVAILABLE = 'Previews are temporarily unavailable. Please try again later.';

    public function stylize(string $photoPath, Style $style): string
    {
        $key = config('services.openai.key');

        if (! $key) {
            Log::critical('OpenAI API key is not configured; previews are unavailable.');

            throw new PermanentProviderException(self::UNAVAILABLE);
        }

        if (! is_string($style->prompt) || $style->prompt === '') {
            Log::error('A style without a prompt was sent for restyling.', ['style_id' => $style->id]);

            throw new PermanentProviderException(self::FAILED);
        }

        $contents = is_file($photoPath) ? file_get_contents($photoPath) : false;

        if ($contents === false) {
            throw new PermanentProviderException('We could not read that photo.');
        }

        $settings = config('stylizer.openai');

        try {
            $response = Http::withToken($key)
                ->connectTimeout(10)
                ->timeout((int) $settings['timeout_seconds'])
                ->attach('image', $contents, 'photo.jpg', ['Content-Type' => 'image/jpeg'])
                ->post(self::URL, [
                    'model' => $settings['model'],
                    'prompt' => $style->prompt,
                    'size' => $settings['size'],
                    'quality' => $settings['quality'],
                    'input_fidelity' => $settings['input_fidelity'],
                    'output_format' => 'png',
                ]);
        } catch (ConnectionException $e) {
            throw new TransientProviderException('The image service could not be reached.', 0, $e);
        }

        return $this->imageFrom($response);
    }

    private function imageFrom(Response $response): string
    {
        if ($response->successful()) {
            $encoded = $response->json('data.0.b64_json');
            $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;

            if ($bytes === false || @getimagesizefromstring($bytes) === false) {
                Log::warning('OpenAI returned no usable image.', ['status' => $response->status()]);

                throw new PermanentProviderException(self::FAILED);
            }

            return $bytes;
        }

        $status = $response->status();
        $code = (string) $response->json('error.code');
        $message = strtolower((string) $response->json('error.message'));

        // Our account is the problem (bad key, no access, out of quota): refund the customer,
        // tell the operator. Only the status and error code are logged, never bodies or keys.
        $outOfQuota = $status === 429 && str_contains($code, 'quota');

        if ($status === 401 || $status === 403 || $outOfQuota || $code === 'billing_hard_limit_reached') {
            Log::critical('OpenAI account problem: previews are failing.', ['status' => $status, 'code' => $code]);

            throw new PermanentProviderException(self::UNAVAILABLE);
        }

        if ($status === 429 || $status === 408 || $status >= 500) {
            throw new TransientProviderException("The image service is busy (HTTP {$status}).");
        }

        if ($code === 'moderation_blocked' || str_contains($message, 'safety system')) {
            throw new PermanentProviderException(self::REFUSED);
        }

        Log::warning('OpenAI rejected an image edit request.', ['status' => $status, 'code' => $code]);

        throw new PermanentProviderException(self::FAILED);
    }
}
