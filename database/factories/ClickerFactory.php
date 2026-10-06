<?php

namespace Database\Factories;

use App\Enums\ClickerStatus;
use App\Models\Clicker;
use App\Models\ClickerBody;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Clicker> */
class ClickerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'clicker_body_id' => ClickerBody::factory(),
            'name' => 'My clicker',
            'settings' => [
                'colors' => [
                    ['hex' => '#1D4ED8', 'height_mm' => 1, 'enabled' => true],
                    ['hex' => '#DC2626', 'height_mm' => 2, 'enabled' => true],
                ],
                'scale' => 1,
                'offset_x' => 0,
                'offset_y' => 0,
                'outline_mm' => 0,
                'outline_hex' => '#111111',
            ],
            'status' => ClickerStatus::Draft,
        ];
    }
}
