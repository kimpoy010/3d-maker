<?php

namespace Database\Factories;

use App\Enums\CreationStatus;
use App\Models\Creation;
use App\Models\Style;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Creation> */
class CreationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'style_id' => Style::factory(),
            'source_image_path' => 'uploads/test.jpg',
            'status' => CreationStatus::Queued,
            'cost_credits' => 5,
        ];
    }
}
