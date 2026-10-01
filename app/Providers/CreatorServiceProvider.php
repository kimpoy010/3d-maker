<?php

namespace App\Providers;

use App\Services\ModelProviders\MockProvider;
use App\Services\ModelProviders\ModelProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class CreatorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ModelProvider::class, fn () => match (config('models.provider')) {
            'mock' => new MockProvider,
            default => throw new InvalidArgumentException('Unknown model provider ['.config('models.provider').'].'),
        });
    }

    public function boot(): void
    {
        RateLimiter::for('generate', fn (Request $request) => Limit::perMinute(10)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
