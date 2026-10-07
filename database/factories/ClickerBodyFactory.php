<?php

namespace Database\Factories;

use App\Models\ClickerBody;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ClickerBody> */
class ClickerBodyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'description' => null,
            'preview_image' => null,
            'model_files' => ['resources/clicker/bodies/placeholder-slab/body.stl'],
            'config' => ClickerBody::legacyConfig(),
            'active' => true,
        ];
    }

    /** The generated Cherry MX keycap body: no model files. */
    public function keycap(): static
    {
        return $this->state(fn () => ['model_files' => [], 'config' => ClickerBody::sampleConfig()]);
    }
}
