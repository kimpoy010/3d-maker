<?php

use App\Models\ClickerBody;
use Database\Seeders\ClickerBodySeeder;

it('seeds the development body once, with a complete config and a real model file', function () {
    $this->seed(ClickerBodySeeder::class);
    $this->seed(ClickerBodySeeder::class);

    $body = ClickerBody::where('slug', 'placeholder-slab')->firstOrFail();

    expect(ClickerBody::count())->toBe(1)
        ->and($body->active)->toBeTrue()
        ->and($body->config['cap'])->toHaveKeys(['shape', 'width', 'height', 'corner_radius', 'top_z', 'inset'])
        ->and($body->config['artwork_area'])->toHaveKeys(['cx', 'cy', 'width', 'height'])
        ->and($body->config)->toHaveKeys(['max_layer_height', 'min_feature']);

    foreach ($body->model_files as $file) {
        expect(base_path($file))->toBeFile();
    }
});

it('has sensible clicker limits in config', function () {
    expect(config('clicker.max_per_user'))->toBe(20)
        ->and(config('clicker.max_colors'))->toBe(8)
        ->and(config('clicker.upload.max_edge'))->toBe(4096)
        ->and(config('clicker.build_timeout_seconds'))->toBeLessThan(120);
});
