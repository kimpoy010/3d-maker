<?php

use App\Console\Commands\SmokeProviders;
use App\Models\Style;
use App\Services\ModelProviders\ModelProvider;
use App\Services\ModelProviders\ProviderResult;
use App\Services\ModelProviders\ProviderState;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config([
        'stylizer.provider' => 'mock',
        'stylizer.mock.fail_rate' => 0,
        'models.provider' => 'mock',
        'models.mock.delay_seconds' => 0,
        'models.mock.fail_rate' => 0,
    ]);
    Style::factory()->create(['subject' => 'person', 'look' => 'chibi']);
    $this->photo = tempnam(sys_get_temp_dir(), 'photo');
    file_put_contents($this->photo, fakeJpegBytes());
});

it('runs one restyle and one 3D build and saves the results', function () {
    $this->artisan('providers:smoke', ['photo' => $this->photo, 'style' => 'chibi'])
        ->expectsConfirmation('This makes paid API calls with your configured keys. Continue?', 'yes')
        ->assertSuccessful();

    $files = collect(Storage::disk('local')->allFiles())->filter(fn ($f) => str_starts_with($f, 'smoke/'));

    expect($files->contains(fn ($f) => str_ends_with($f, '/restyled.png')))->toBeTrue()
        ->and($files->contains(fn ($f) => str_ends_with($f, '/model.glb')))->toBeTrue()
        ->and($files->contains(fn ($f) => str_ends_with($f, '/print.stl')))->toBeTrue();
});

it('can stop after the restyle step', function () {
    $this->artisan('providers:smoke', ['photo' => $this->photo, 'style' => 'chibi', '--skip-3d' => true])
        ->expectsConfirmation('This makes paid API calls with your configured keys. Continue?', 'yes')
        ->assertSuccessful();

    $files = collect(Storage::disk('local')->allFiles())->filter(fn ($f) => str_starts_with($f, 'smoke/'));

    expect($files->contains(fn ($f) => str_ends_with($f, '/restyled.png')))->toBeTrue()
        ->and($files->contains(fn ($f) => str_ends_with($f, '/model.glb')))->toBeFalse()
        ->and($files->contains(fn ($f) => str_ends_with($f, '/print.stl')))->toBeFalse();
});

it('does nothing without confirmation, and rejects unknown styles or missing photos', function () {
    $this->artisan('providers:smoke', ['photo' => $this->photo, 'style' => 'chibi'])
        ->expectsConfirmation('This makes paid API calls with your configured keys. Continue?', 'no')
        ->assertFailed();

    $this->artisan('providers:smoke', ['photo' => $this->photo, 'style' => 'nonexistent'])
        ->expectsConfirmation('This makes paid API calls with your configured keys. Continue?', 'yes')
        ->assertFailed();

    $this->artisan('providers:smoke', ['photo' => '/no/such/photo.jpg', 'style' => 'chibi'])
        ->assertFailed();

    expect(collect(Storage::disk('local')->allFiles())->filter(fn ($f) => str_starts_with($f, 'smoke/')))->toBeEmpty();
});

it('reports a timeout when the 3D build never finishes', function () {
    $this->app->instance(ModelProvider::class, new class implements ModelProvider
    {
        public function start(string $imagePath, Style $style): string
        {
            return 'task-1';
        }

        public function status(string $providerJobId): ProviderResult
        {
            return new ProviderResult(ProviderState::Running, progress: 10);
        }

        public function download(string $url): string
        {
            return '';
        }
    });
    SmokeProviders::$maxWaitSeconds = -1;
    SmokeProviders::$pollSeconds = 0;

    try {
        $this->artisan('providers:smoke', ['photo' => $this->photo, 'style' => 'chibi'])
            ->expectsConfirmation('This makes paid API calls with your configured keys. Continue?', 'yes')
            ->expectsOutputToContain('Timed out waiting for the 3D build.')
            ->assertFailed();
    } finally {
        SmokeProviders::$maxWaitSeconds = 600;
        SmokeProviders::$pollSeconds = 5;
    }

    $files = collect(Storage::disk('local')->allFiles())->filter(fn ($f) => str_starts_with($f, 'smoke/'));
    expect($files->contains(fn ($f) => str_ends_with($f, '/model.glb')))->toBeFalse();
});
