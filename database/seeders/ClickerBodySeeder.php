<?php

namespace Database\Seeders;

use App\Models\ClickerBody;
use Illuminate\Database\Seeder;

class ClickerBodySeeder extends Seeder
{
    public function run(): void
    {
        // A development stand-in. Replace it with the real, print-tested clicker bodies before launch:
        // drop each body's STL/3MF into resources/clicker/bodies/<slug>/ and add an entry like this one.
        ClickerBody::updateOrCreate(
            ['slug' => 'placeholder-slab'],
            [
                'name' => 'Placeholder slab (development only)',
                'description' => 'A plain rounded slab used until the real clicker bodies are added. It has no switch cavity.',
                'preview_image' => null,
                'model_files' => ['resources/clicker/bodies/placeholder-slab/body.stl'],
                'config' => ClickerBody::sampleConfig(),
                'active' => true,
            ],
        );
    }
}
