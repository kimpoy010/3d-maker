<?php

namespace App\Providers;

use App\Services\ModelProviders\MeshyProvider;
use App\Services\ModelProviders\MockProvider;
use App\Services\ModelProviders\ModelProvider;
use App\Services\Stylizers\ImageStylizer;
use App\Services\Stylizers\MockStylizer;
use App\Services\Stylizers\OpenAiStylizer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use RuntimeException;

class CreatorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ModelProvider::class, function () {
            $name = config('models.provider');

            $this->refuseMockInProduction($name, 'MODEL_PROVIDER');

            return match ($name) {
                'mock' => new MockProvider,
                'meshy' => app(MeshyProvider::class),
                default => throw new InvalidArgumentException("Unknown model provider [{$name}]."),
            };
        });

        $this->app->bind(ImageStylizer::class, function () {
            $name = config('stylizer.provider');

            $this->refuseMockInProduction($name, 'STYLIZER_PROVIDER');

            return match ($name) {
                'mock' => new MockStylizer,
                'openai' => app(OpenAiStylizer::class),
                default => throw new InvalidArgumentException("Unknown stylizer provider [{$name}]."),
            };
        });
    }

    /** Checked when the provider is resolved, not at boot, so artisan deploy commands still run. */
    private function refuseMockInProduction(string $name, string $envKey): void
    {
        if ($name === 'mock' && $this->app->isProduction()) {
            throw new RuntimeException("{$envKey}=mock is not allowed in production.");
        }
    }

    public function boot(): void
    {
        RateLimiter::for('generate', fn (Request $request) => Limit::perMinute(10)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
