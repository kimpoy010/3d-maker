<?php

use App\Enums\Subject;
use App\Models\Style;
use App\Services\ModelProviders\MockAssets;
use App\Services\ModelProviders\MockProvider;
use App\Services\ModelProviders\ModelProvider;
use App\Services\ModelProviders\PermanentProviderException;
use App\Services\ModelProviders\ProviderState;

beforeEach(function () {
    config(['models.mock.delay_seconds' => 10, 'models.mock.fail_rate' => 0]);
    $this->provider = new MockProvider;
    $this->style = Style::factory()->make(['subject' => Subject::Pet]);
});

it('is the bound provider by default', function () {
    expect(app(ModelProvider::class))->toBeInstanceOf(MockProvider::class);
});

it('moves from pending to running to succeeded over the delay', function () {
    $id = $this->provider->start('/tmp/photo.jpg', $this->style);

    expect($this->provider->status($id)->state)->toBe(ProviderState::Pending);

    $this->travel(5)->seconds();
    $running = $this->provider->status($id);
    expect($running->state)->toBe(ProviderState::Running)
        ->and($running->progress)->toBe(50);

    $this->travel(6)->seconds();
    $done = $this->provider->status($id);
    expect($done->state)->toBe(ProviderState::Succeeded)
        ->and($done->modelUrl)->toBe('mock://model/pet')
        ->and($done->thumbnailUrl)->toBe('mock://thumbnail/pet')
        ->and($done->progress)->toBe(100);
});

it('fails every job when the fail rate is 1', function () {
    config(['models.mock.fail_rate' => 1, 'models.mock.delay_seconds' => 0]);

    $result = $this->provider->status($this->provider->start('/tmp/photo.jpg', $this->style));

    expect($result->state)->toBe(ProviderState::Failed)->and($result->error)->not->toBeNull();
});

it('reports unknown jobs as failed', function () {
    expect($this->provider->status('nope')->state)->toBe(ProviderState::Failed);
});

it('downloads a valid GLB per subject', function () {
    $bytes = $this->provider->download('mock://model/person');

    expect(substr($bytes, 0, 4))->toBe('glTF')
        ->and(unpack('V', substr($bytes, 4, 4))[1])->toBe(2)
        ->and(unpack('V', substr($bytes, 8, 4))[1])->toBe(strlen($bytes));
});

it('downloads a PNG thumbnail', function () {
    $bytes = $this->provider->download('mock://thumbnail/object');

    expect(substr($bytes, 0, 8))->toBe("\x89PNG\r\n\x1a\n");
});

it('refuses to download unknown urls', function () {
    expect(fn () => $this->provider->download('https://evil.test/x.glb'))
        ->toThrow(PermanentProviderException::class);
});

it('builds distinct assets per subject', function () {
    expect(MockAssets::glb(Subject::Person))->not->toBe(MockAssets::glb(Subject::Pet));
});
