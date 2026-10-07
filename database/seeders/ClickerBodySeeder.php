<?php

namespace Database\Seeders;

use App\Models\ClickerBody;
use Illuminate\Database\Seeder;

class ClickerBodySeeder extends Seeder
{
    public function run(): void
    {
        // Created once and never touched again, so an operator deactivating it sticks.
        // The body is generated in code (no model files); the stem numbers live in its config.
        ClickerBody::firstOrCreate(
            ['slug' => 'cherry-mx-keycap'],
            [
                'name' => 'Cherry MX keycap',
                'description' => 'A 30 mm keycap with a Cherry MX cross socket. Choose a square, circle or traced base.',
                'preview_image' => null,
                'model_files' => [],
                'config' => ClickerBody::sampleConfig(),
                'active' => true,
            ],
        );
    }
}
