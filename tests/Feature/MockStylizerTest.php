<?php

use App\Models\Style;
use App\Services\ModelProviders\MockProvider;
use App\Services\ModelProviders\ModelProvider;
use App\Services\ModelProviders\PermanentProviderException;
use App\Services\Stylizers\ImageStylizer;
use App\Services\Stylizers\MockStylizer;

beforeEach(function () {
    config(['stylizer.mock.fail_rate' => 0]);
    $this->photo = tempnam(sys_get_temp_dir(), 'photo');
    file_put_contents($this->photo, fakeJpegBytes(300, 200));
    $this->style = Style::factory()->make();
});

it('returns a png of the same size with a coloured border', function () {
    $png = (new MockStylizer)->stylize($this->photo, $this->style);

    expect(substr($png, 0, 8))->toBe("\x89PNG\r\n\x1a\n");

    $image = imagecreatefromstring($png);
    expect([imagesx($image), imagesy($image)])->toBe([300, 200]);

    $corner = imagecolorsforindex($image, imagecolorat($image, 0, 0));
    expect([$corner['red'], $corner['green'], $corner['blue']])->toBe([230, 120, 90]);
});

it('refuses every photo when the fail rate is 1', function () {
    config(['stylizer.mock.fail_rate' => 1]);

    expect(fn () => (new MockStylizer)->stylize($this->photo, $this->style))
        ->toThrow(PermanentProviderException::class);
});

it('rejects unreadable photos', function () {
    expect(fn () => (new MockStylizer)->stylize('/no/such/photo.jpg', $this->style))
        ->toThrow(PermanentProviderException::class);
});

it('binds the mock stylizer by default', function () {
    expect(app(ImageStylizer::class))->toBeInstanceOf(MockStylizer::class);
});

it('rejects an unknown stylizer provider', function () {
    config(['stylizer.provider' => 'nope']);

    expect(fn () => app(ImageStylizer::class))->toThrow(InvalidArgumentException::class);
});

it('refuses mock providers in production', function () {
    app()->instance('env', 'production');

    expect(fn () => app(ImageStylizer::class))->toThrow(RuntimeException::class);
    expect(fn () => app(ModelProvider::class))->toThrow(RuntimeException::class);
});

it('still binds the mock model provider outside production', function () {
    expect(app(ModelProvider::class))->toBeInstanceOf(MockProvider::class);
});
