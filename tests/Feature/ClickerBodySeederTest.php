<?php

use App\Models\ClickerBody;
use Database\Seeders\ClickerBodySeeder;

it('seeds the Cherry MX keycap body once, active, with a generator config and no model files', function () {
    $this->seed(ClickerBodySeeder::class);
    $this->seed(ClickerBodySeeder::class);

    $body = ClickerBody::where('name', 'Cherry MX keycap')->firstOrFail();

    expect(ClickerBody::count())->toBe(1)
        ->and($body->active)->toBeTrue()
        ->and($body->model_files)->toBe([])
        ->and($body->config['generator'])->toBe('mx_keycap')
        ->and($body->config['keycap']['size'])->toBe(30)
        ->and($body->config['keycap']['stem'])->toHaveKeys(['span', 'arm', 'wall'])
        ->and($body->config['cap'])->toHaveKeys(['shape', 'width', 'height', 'corner_radius', 'top_z', 'inset'])
        ->and($body->config['artwork_area'])->toHaveKeys(['cx', 'cy', 'width', 'height'])
        ->and($body->config)->toHaveKeys(['max_layer_height', 'min_feature'])
        ->and($body->config['switch'])->toBe('Cherry MX');
});

it('does not seed the placeholder slab, which stays creatable through its factory', function () {
    $this->seed(ClickerBodySeeder::class);

    expect(ClickerBody::where('slug', 'placeholder-slab')->exists())->toBeFalse();

    $slab = ClickerBody::factory()->create();
    foreach ($slab->model_files as $file) {
        expect(base_path($file))->toBeFile();
    }
});

it('never reactivates the keycap body on a re-seed', function () {
    $this->seed(ClickerBodySeeder::class);
    ClickerBody::where('name', 'Cherry MX keycap')->update(['active' => false]);

    $this->seed(ClickerBodySeeder::class);

    expect(ClickerBody::where('name', 'Cherry MX keycap')->firstOrFail()->active)->toBeFalse()
        ->and(ClickerBody::count())->toBe(1);
});

it('seeds the keycap body in production too, since it is a real body', function () {
    $this->app['env'] = 'production';

    (new ClickerBodySeeder)->run();

    expect(ClickerBody::where('name', 'Cherry MX keycap')->count())->toBe(1);
});

it('has sensible clicker limits in config', function () {
    expect(config('clicker.max_per_user'))->toBe(20)
        ->and(config('clicker.max_colors'))->toBe(8)
        ->and(config('clicker.upload.max_edge'))->toBe(4096)
        ->and(config('clicker.build_timeout_seconds'))->toBeLessThan(120);
});
