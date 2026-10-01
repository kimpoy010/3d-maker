<?php

namespace App\Providers;

use App\Services\ModelProviders\MockProvider;
use App\Services\ModelProviders\ModelProvider;
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
}
