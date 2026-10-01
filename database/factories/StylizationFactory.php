<?php

namespace Database\Factories;

use App\Enums\StylizationStatus;
use App\Models\Stylization;
use App\Models\Style;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Stylization> */
class StylizationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'style_id' => Style::factory(),
            'source_image_path' => 'stylizations/test/original.jpg',
            'status' => StylizationStatus::Queued,
            'cost_credits' => 1,
        ];
    }
}
