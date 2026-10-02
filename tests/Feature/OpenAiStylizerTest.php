<?php

use App\Models\Style;
use App\Services\ModelProviders\PermanentProviderException;
use App\Services\ModelProviders\TransientProviderException;
use App\Services\Stylizers\ImageStylizer;
use App\Services\Stylizers\OpenAiStylizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config([
        'services.openai.key' => 'test-key',
        'stylizer.openai.model' => 'gpt-image-1.5',
        'stylizer.openai.quality' => 'medium',
        'stylizer.openai.size' => '1024x1536',
    ]);
    $this->photo = tempnam(sys_get_temp_dir(), 'photo');
    file_put_contents($this->photo, fakeJpegBytes(300, 400));
    $this->style = Style::factory()->make(['prompt' => 'Make it a tiny vinyl figure.']);
    $this->png = fakeJpegBytes(64, 96); // any decodable image bytes will do for the response
    $this->ok = fn () => Http::response(['data' => [['b64_json' => base64_encode($this->png)]]], 200);
});

it('sends the photo and the style prompt to the image edit endpoint', function () {
    Http::fake(['api.openai.com/*' => ($this->ok)()]);

    $result = (new OpenAiStylizer)->stylize($this->photo, $this->style);

    expect($result)->toBe($this->png);
    Http::assertSent(function (Request $request) {
        // Multipart: data() is a list of parts, not a field map.
        $data = collect($request->data())->mapWithKeys(fn ($part) => [$part['name'] => $part['contents']])->all();

        return $request->url() === 'https://api.openai.com/v1/images/edits'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $data['model'] === 'gpt-image-1.5'
            && $data['prompt'] === 'Make it a tiny vinyl figure.'
            && $data['size'] === '1024x1536'
            && $data['quality'] === 'medium'
            && $data['input_fidelity'] === 'low'
            && $data['output_format'] === 'jpeg'
            && $data['output_compression'] == 90
            && isset($data['image']);
    });
});

it('leaves out input fidelity for gpt-image-2, which does not accept it', function () {
    config(['stylizer.openai.model' => 'gpt-image-2']);
    Http::fake(['api.openai.com/*' => ($this->ok)()]);

    (new OpenAiStylizer)->stylize($this->photo, $this->style);

    Http::assertSent(function (Request $request) {
        $names = collect($request->data())->pluck('name');

        return $names->contains('model') && ! $names->contains('input_fidelity');
    });
});

it('is bound when the provider is openai', function () {
    config(['stylizer.provider' => 'openai']);

    expect(app(ImageStylizer::class))->toBeInstanceOf(OpenAiStylizer::class);
});

it('maps a content-policy refusal to a customer-safe permanent error', function () {
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['code' => 'moderation_blocked', 'message' => 'Your request was rejected by the safety system.']], 400)]);

    expect(fn () => (new OpenAiStylizer)->stylize($this->photo, $this->style))
        ->toThrow(PermanentProviderException::class, OpenAiStylizer::REFUSED);
});

it('maps other bad requests to a generic permanent error', function () {
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['code' => 'invalid_value', 'message' => 'bad size']], 400)]);

    expect(fn () => (new OpenAiStylizer)->stylize($this->photo, $this->style))
        ->toThrow(PermanentProviderException::class, OpenAiStylizer::FAILED);
});

it('refunds the customer but alerts the operator on account problems', function (int $status, array $body) {
    Log::spy();
    Http::fake(['api.openai.com/*' => Http::response($body, $status)]);

    expect(fn () => (new OpenAiStylizer)->stylize($this->photo, $this->style))
        ->toThrow(PermanentProviderException::class, OpenAiStylizer::UNAVAILABLE);

    Log::shouldHaveReceived('critical')->once();
})->with([
    'bad key' => [401, ['error' => ['code' => 'invalid_api_key']]],
    'not verified or no access' => [403, ['error' => ['code' => 'model_not_found']]],
    'out of quota' => [429, ['error' => ['code' => 'insufficient_quota']]],
    'quota by type only' => [429, ['error' => ['type' => 'insufficient_quota']]],
    'quota by message only' => [429, ['error' => ['message' => 'You exceeded your current Quota, please check your plan.']]],
    'billing by message only' => [429, ['error' => ['message' => 'Billing issue on this account.']]],
    'billing hard limit' => [429, ['error' => ['code' => 'billing_hard_limit_reached']]],
]);

it('retries later on rate limits and server errors', function (int $status) {
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['code' => 'rate_limit_exceeded']], $status)]);

    expect(fn () => (new OpenAiStylizer)->stylize($this->photo, $this->style))
        ->toThrow(TransientProviderException::class);
})->with([408, 429, 500, 502, 503]);

it('retries later when the connection fails or times out', function () {
    Http::fake(['api.openai.com/*' => fn () => throw new ConnectionException('timeout')]);

    expect(fn () => (new OpenAiStylizer)->stylize($this->photo, $this->style))
        ->toThrow(TransientProviderException::class);
});

it('fails permanently when the response has no usable image', function () {
    Http::fake(['api.openai.com/*' => Http::response(['data' => [['b64_json' => base64_encode('not an image')]]], 200)]);

    expect(fn () => (new OpenAiStylizer)->stylize($this->photo, $this->style))
        ->toThrow(PermanentProviderException::class, OpenAiStylizer::FAILED);
});

it('is unavailable without an api key and never calls out', function () {
    Log::spy();
    config(['services.openai.key' => null]);
    Http::fake();

    expect(fn () => (new OpenAiStylizer)->stylize($this->photo, $this->style))
        ->toThrow(PermanentProviderException::class, OpenAiStylizer::UNAVAILABLE);

    Http::assertNothingSent();
    Log::shouldHaveReceived('critical')->once();
});

it('refuses a style that has no prompt', function () {
    Http::fake();
    $style = Style::factory()->make(['prompt' => null]);

    expect(fn () => (new OpenAiStylizer)->stylize($this->photo, $style))
        ->toThrow(PermanentProviderException::class, OpenAiStylizer::FAILED);

    Http::assertNothingSent();
});

it('never logs the key, the prompt or image bytes', function () {
    Log::spy();
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['code' => 'invalid_api_key']], 401)]);

    try {
        (new OpenAiStylizer)->stylize($this->photo, $this->style);
    } catch (PermanentProviderException) {
    }

    Log::shouldHaveReceived('critical')->once();

    foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'] as $level) {
        Log::shouldNotHaveReceived($level, fn (string $message, array $context = []) => str_contains($message.json_encode($context), 'test-key')
            || str_contains($message.json_encode($context), 'tiny vinyl figure'));
    }
});

it('never logs the key or the prompt on any failure path', function (int $status, array $body) {
    Log::spy();
    Http::fake(['api.openai.com/*' => Http::response($body, $status)]);

    try {
        (new OpenAiStylizer)->stylize($this->photo, $this->style);
    } catch (PermanentProviderException) {
    }

    foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'] as $level) {
        Log::shouldNotHaveReceived($level, fn (string $message, array $context = []) => str_contains($message.json_encode($context), 'test-key')
            || str_contains($message.json_encode($context), 'tiny vinyl figure'));
    }
})->with([
    'bad request' => [400, ['error' => ['code' => 'invalid_value', 'message' => 'bad size']]],
    'bad image' => [200, ['data' => [['b64_json' => 'bm90IGFuIGltYWdl']]]],
    'bad key' => [401, ['error' => ['code' => 'invalid_api_key']]],
]);

it('fails permanently when the image has a valid header but cannot be decoded', function () {
    $truncated = truncatedPngBytes();
    expect(@getimagesizefromstring($truncated))->not->toBeFalse();
    Http::fake(['api.openai.com/*' => Http::response(['data' => [['b64_json' => base64_encode($truncated)]]], 200)]);

    expect(fn () => (new OpenAiStylizer)->stylize($this->photo, $this->style))
        ->toThrow(PermanentProviderException::class, OpenAiStylizer::FAILED);
});

it('treats the safety-system text as a refusal even without an error code', function () {
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Rejected by the safety system.']], 400)]);

    expect(fn () => (new OpenAiStylizer)->stylize($this->photo, $this->style))
        ->toThrow(PermanentProviderException::class, OpenAiStylizer::REFUSED);
});

it('refuses an empty prompt without sending a request', function () {
    Http::fake();
    $style = Style::factory()->make(['prompt' => '']);

    expect(fn () => (new OpenAiStylizer)->stylize($this->photo, $style))
        ->toThrow(PermanentProviderException::class, OpenAiStylizer::FAILED);

    Http::assertNothingSent();
});

it('logs a rejected request as an error with only the status and code', function () {
    Log::spy();
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['code' => 'invalid_value', 'message' => 'bad size']], 400)]);

    try {
        (new OpenAiStylizer)->stylize($this->photo, $this->style);
    } catch (PermanentProviderException) {
    }

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []) => $context === ['status' => 400, 'code' => 'invalid_value'])->once();
    Log::shouldNotHaveReceived('warning');
});

it('sends no compression setting when png is configured', function () {
    config(['stylizer.openai.output_format' => 'png']);
    Http::fake(['api.openai.com/*' => ($this->ok)()]);

    (new OpenAiStylizer)->stylize($this->photo, $this->style);

    Http::assertSent(function (Request $request) {
        $data = collect($request->data())->mapWithKeys(fn ($part) => [$part['name'] => $part['contents']])->all();

        return $data['output_format'] === 'png' && ! array_key_exists('output_compression', $data);
    });
});
