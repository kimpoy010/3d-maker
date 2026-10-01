<?php

namespace Database\Factories;

use App\Enums\Subject;
use App\Models\Style;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Style> */
class StyleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subject' => Subject::Person,
            'name' => fake()->unique()->words(2, true),
            'look' => fake()->unique()->slug(2),
            'provider_params' => ['texture' => true],
            'credit_cost' => 5,
            'active' => true,
            'preview_image' => null,
        ];
    }
}
