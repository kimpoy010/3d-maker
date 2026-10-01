<?php

use App\Enums\Subject;
use App\Models\Style;
use App\Services\ModelProviders\MeshyProvider;
use App\Services\ModelProviders\ModelProvider;
use App\Services\ModelProviders\PermanentProviderException;
use App\Services\ModelProviders\ProviderState;
use App\Services\ModelProviders\TransientProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config(['services.meshy.key' => 'meshy-key', 'services.meshy.model' => null, 'models.meshy.default_polycount' => 30000]);
    $this->image = tempnam(sys_get_temp_dir(), 'img');
    file_put_contents($this->image, fakeJpegBytes(256, 384));
    $this->style = Style::factory()->make(['subject' => Subject::Person, 'provider_params' => ['target_faces' => 20000]]);
    $this->provider = new MeshyProvider;
});

it('is bound when the provider is meshy', function () {
    config(['models.provider' => 'meshy']);

    expect(app(ModelProvider::class))->toBeInstanceOf(MeshyProvider::class);
});

describe('start', function () {
    it('creates an image-to-3d task asking for a textured glb and a printable stl', function () {
        Http::fake(['api.meshy.ai/*' => Http::response(['result' => 'task_123'], 202)]);

        expect($this->provider->start($this->image, $this->style))->toBe('task_123');

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === 'https://api.meshy.ai/openapi/v1/image-to-3d'
                && $request->hasHeader('Authorization', 'Bearer meshy-key')
                && str_starts_with($body['image_url'], 'data:image/jpeg;base64,')
                && $body['should_texture'] === true
                && $body['enable_pbr'] === false
                && $body['should_remesh'] === true
                && $body['target_polycount'] === 20000
                && $body['target_formats'] === ['glb', 'stl']
                && ! array_key_exists('ai_model', $body);
        });
    });

    it('falls back to the default polycount and passes a configured model', function () {
        config(['services.meshy.model' => 'meshy-6']);
        Http::fake(['api.meshy.ai/*' => Http::response(['result' => 'task_1'], 202)]);

        $this->provider->start($this->image, Style::factory()->make(['provider_params' => []]));

        Http::assertSent(fn (Request $r) => $r->data()['target_polycount'] === 30000 && $r->data()['ai_model'] === 'meshy-6');
    });

    it('maps request problems', function (int $status, string $exception) {
        Http::fake(['api.meshy.ai/*' => Http::response(['message' => 'x'], $status)]);

        expect(fn () => $this->provider->start($this->image, $this->style))->toThrow($exception);
    })->with([
        'bad image' => [400, PermanentProviderException::class],
        'rate limit' => [429, TransientProviderException::class],
        'server error' => [500, TransientProviderException::class],
        'unavailable' => [503, TransientProviderException::class],
        'request timeout' => [408, TransientProviderException::class],
    ]);

    it('refunds the customer but alerts the operator on account problems', function (int $status) {
        Log::spy();
        Http::fake(['api.meshy.ai/*' => Http::response(['message' => 'x'], $status)]);

        expect(fn () => $this->provider->start($this->image, $this->style))->toThrow(PermanentProviderException::class);

        Log::shouldHaveReceived('critical')->once();
    })->with([401, 402, 403]);

    it('shows the customer a fixed message on account problems, never provider text', function () {
        Http::fake(['api.meshy.ai/*' => Http::response(['message' => 'secret provider text'], 402)]);

        try {
            $this->provider->start($this->image, $this->style);
        } catch (PermanentProviderException $e) {
            expect($e->getMessage())->toBe(MeshyProvider::UNAVAILABLE);

            return;
        }

        $this->fail('Expected a PermanentProviderException.');
    });

    it('retries later when the connection fails', function () {
        Http::fake(['api.meshy.ai/*' => fn () => throw new ConnectionException('timeout')]);

        expect(fn () => $this->provider->start($this->image, $this->style))->toThrow(TransientProviderException::class);
    });

    it('fails permanently when no task id comes back', function () {
        Http::fake(['api.meshy.ai/*' => Http::response(['unexpected' => true], 200)]);

        expect(fn () => $this->provider->start($this->image, $this->style))->toThrow(PermanentProviderException::class);
    });

    it('is unavailable without an api key and never calls out', function () {
        Log::spy();
        config(['services.meshy.key' => null]);
        Http::fake();

        expect(fn () => $this->provider->start($this->image, $this->style))->toThrow(PermanentProviderException::class);

        Http::assertNothingSent();
        Log::shouldHaveReceived('critical')->once();
    });

    it('never follows redirects from the api', function () {
        Http::fake(['api.meshy.ai/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/'])]);

        expect(fn () => $this->provider->start($this->image, $this->style))->toThrow(PermanentProviderException::class);
        Http::assertSentCount(1);
    });

    it('asks the http client not to follow redirects', function () {
        $options = null;
        Http::fake(['api.meshy.ai/*' => function ($request, $o) use (&$options) {
            $options = $o;

            return Http::response(['result' => 't'], 202);
        }]);

        $this->provider->start($this->image, $this->style);

        expect($options['allow_redirects'])->toBeFalse()
            ->and($options['timeout'])->toBe(20);
    });

    it('does not put the key or image bytes in the log when a request is rejected', function () {
        Log::spy();
        Http::fake(['api.meshy.ai/*' => Http::response(['message' => 'x'], 400)]);

        try {
            $this->provider->start($this->image, $this->style);
        } catch (PermanentProviderException) {
        }

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []) {
            $logged = $message.json_encode($context);

            return ! str_contains($logged, 'meshy-key') && ! str_contains($logged, 'base64');
        })->once();
    });

    it('clamps or replaces an unusable target polycount', function (mixed $configured, int $sent) {
        Http::fake(['api.meshy.ai/*' => Http::response(['result' => 't'], 202)]);

        $this->provider->start($this->image, Style::factory()->make(['provider_params' => ['target_faces' => $configured]]));

        Http::assertSent(fn (Request $r) => $r->data()['target_polycount'] === $sent);
    })->with([
        'valid' => [20000, 20000],
        'zero' => [0, 30000],
        'negative' => [-5, 30000],
        'not a number' => ['abc', 30000],
        'too high' => [1000000000, 300000],
        'too low' => [50, 100],
    ]);

    it('never logs the key or image bytes', function () {
        Log::spy();
        Http::fake(['api.meshy.ai/*' => Http::response(['message' => 'x'], 401)]);

        try {
            $this->provider->start($this->image, $this->style);
        } catch (PermanentProviderException) {
        }

        Log::shouldHaveReceived('critical')->withArgs(function (string $message, array $context = []) {
            return ! str_contains($message.json_encode($context), 'meshy-key');
        });
    });
});

describe('status', function () {
    it('maps task states', function (string $meshyStatus, ProviderState $state) {
        Http::fake(['api.meshy.ai/*' => Http::response(['status' => $meshyStatus, 'progress' => 40], 200)]);

        expect($this->provider->status('task_123')->state)->toBe($state);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.meshy.ai/openapi/v1/image-to-3d/task_123' && $r->method() === 'GET');
    })->with([
        ['PENDING', ProviderState::Pending],
        ['IN_PROGRESS', ProviderState::Running],
        ['FAILED', ProviderState::Failed],
        ['CANCELED', ProviderState::Failed],
    ]);

    it('reports progress while running', function () {
        Http::fake(['api.meshy.ai/*' => Http::response(['status' => 'IN_PROGRESS', 'progress' => 40], 200)]);

        expect($this->provider->status('t')->progress)->toBe(40);
    });

    it('returns the glb, stl and thumbnail urls when finished', function () {
        Http::fake(['api.meshy.ai/*' => Http::response([
            'status' => 'SUCCEEDED',
            'progress' => 100,
            'model_urls' => ['glb' => 'https://assets.meshy.ai/a/model.glb?Expires=1', 'stl' => 'https://assets.meshy.ai/a/model.stl?Expires=1'],
            'thumbnail_url' => 'https://assets.meshy.ai/a/preview.png?Expires=1',
        ], 200)]);

        $result = $this->provider->status('t');

        expect($result->state)->toBe(ProviderState::Succeeded)
            ->and($result->modelUrl)->toBe('https://assets.meshy.ai/a/model.glb?Expires=1')
            ->and($result->printModelUrl)->toBe('https://assets.meshy.ai/a/model.stl?Expires=1')
            ->and($result->thumbnailUrl)->toBe('https://assets.meshy.ai/a/preview.png?Expires=1');
    });

    it('shows a plain message, never the provider text, when the build fails', function () {
        Log::spy();
        Http::fake(['api.meshy.ai/*' => Http::response(['status' => 'FAILED', 'task_error' => ['message' => 'internal gpu error 0xDEAD']], 200)]);

        $result = $this->provider->status('t');

        expect($result->state)->toBe(ProviderState::Failed)
            ->and($result->error)->toBe('The 3D model could not be built from this image.')
            ->and($result->error)->not->toContain('0xDEAD');
        Log::shouldHaveReceived('warning')->once();
    });

    it('treats an unknown task as failed and logs it', function () {
        Log::spy();
        Http::fake(['api.meshy.ai/*' => Http::response([], 404)]);

        expect($this->provider->status('gone')->state)->toBe(ProviderState::Failed);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $c = []) => $m === 'Meshy task not found' && $c === ['task' => 'gone'])->once();
    });

    it('retries later when the request times out', function () {
        Http::fake(['api.meshy.ai/*' => Http::response([], 408)]);

        expect(fn () => $this->provider->status('t'))->toThrow(TransientProviderException::class);
    });

    it('stays pending but logs an unknown status word', function (array $body, string $logged) {
        Log::spy();
        Http::fake(['api.meshy.ai/*' => Http::response($body, 200)]);

        expect($this->provider->status('t')->state)->toBe(ProviderState::Pending);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $c = []) => $m === 'Meshy returned an unknown task status' && $c === ['status' => $logged])->once();
    })->with([
        'new word' => [['status' => 'QUEUED_FOREVER'], 'QUEUED_FOREVER'],
        'missing status' => [[], ''],
        'very long word' => [['status' => str_repeat('x', 100)], str_repeat('x', 40)],
    ]);

    it('does not log a normal pending status as unknown', function () {
        Log::spy();
        Http::fake(['api.meshy.ai/*' => Http::response(['status' => 'PENDING'], 200)]);

        $this->provider->status('t');

        Log::shouldNotHaveReceived('warning');
    });

    it('never follows redirects from the api', function () {
        Http::fake(['api.meshy.ai/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/'])]);

        expect(fn () => $this->provider->status('t'))->toThrow(PermanentProviderException::class);
        Http::assertSentCount(1);
    });

    it('retries later on server errors', function () {
        Http::fake(['api.meshy.ai/*' => Http::response([], 500)]);

        expect(fn () => $this->provider->status('t'))->toThrow(TransientProviderException::class);
    });

    it('retries later when the connection fails', function () {
        Http::fake(['api.meshy.ai/*' => fn () => throw new ConnectionException('timeout')]);

        expect(fn () => $this->provider->status('t'))->toThrow(TransientProviderException::class);
    });
});

describe('download', function () {
    it('fetches files from meshy asset hosts without sending the api key', function () {
        Http::fake(['assets.meshy.ai/*' => Http::response('file-bytes', 200)]);

        expect($this->provider->download('https://assets.meshy.ai/a/model.glb?Expires=1'))->toBe('file-bytes');
        Http::assertSent(fn (Request $r) => ! $r->hasHeader('Authorization'));
    });

    it('never follows redirects, so a meshy url cannot bounce to an internal address', function () {
        Http::fake(['assets.meshy.ai/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/'])]);

        expect(fn () => $this->provider->download('https://assets.meshy.ai/a/model.glb'))->toThrow(PermanentProviderException::class);
        Http::assertSentCount(1);
    });

    it('uses the shorter download timeout and refuses redirects', function () {
        config(['models.meshy.download_timeout_seconds' => 12]);
        $options = null;
        Http::fake(['assets.meshy.ai/*' => function ($request, $o) use (&$options) {
            $options = $o;

            return Http::response('ok', 200);
        }]);

        $this->provider->download('https://assets.meshy.ai/a/model.glb');

        expect($options['timeout'])->toBe(12)
            ->and($options['allow_redirects'])->toBeFalse();
    });

    it('refuses any other host or scheme without making a request', function (string $url) {
        Http::fake();

        expect(fn () => $this->provider->download($url))->toThrow(PermanentProviderException::class);

        Http::assertNothingSent();
    })->with([
        'other host' => 'https://evil.example.com/model.glb',
        'lookalike host' => 'https://assets.meshy.ai.evil.com/model.glb',
        'plain http' => 'http://assets.meshy.ai/model.glb',
        'internal address' => 'https://169.254.169.254/latest/meta-data',
        'not a url' => 'mock://model/person',
    ]);

    it('maps download failures by status', function (int $status, string $exception) {
        Http::fake(['assets.meshy.ai/*' => Http::response('', $status)]);

        expect(fn () => $this->provider->download('https://assets.meshy.ai/x.glb'))->toThrow($exception);
    })->with([
        'expired or forbidden link' => [403, PermanentProviderException::class],
        'missing file' => [404, PermanentProviderException::class],
        'service unavailable' => [503, TransientProviderException::class],
        'request timeout' => [408, TransientProviderException::class],
    ]);

    it('retries later when the download connection fails', function () {
        Http::fake(['assets.meshy.ai/*' => fn () => throw new ConnectionException('timeout')]);

        expect(fn () => $this->provider->download('https://assets.meshy.ai/x.glb'))->toThrow(TransientProviderException::class);
    });
});
