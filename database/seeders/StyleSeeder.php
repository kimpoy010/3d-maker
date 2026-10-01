<?php

namespace Database\Seeders;

use App\Enums\Subject;
use App\Models\Style;
use Illuminate\Database\Seeder;

class StyleSeeder extends Seeder
{
    public function run(): void
    {
        $looks = [
            'realistic' => ['Realistic', 6, ['texture' => true, 'target_faces' => 50000]],
            'cartoon' => ['Cartoon', 5, ['texture' => true, 'target_faces' => 30000]],
            'clay' => ['Clay', 5, ['texture' => false, 'target_faces' => 30000]],
            'chibi' => ['Chibi', 5, ['texture' => true, 'target_faces' => 20000]],
        ];

        $matrix = [
            Subject::Person->value => ['realistic', 'cartoon', 'clay', 'chibi'],
            Subject::Pet->value => ['realistic', 'cartoon', 'clay'],
            Subject::Object->value => ['realistic', 'clay'],
        ];

        foreach ($matrix as $subject => $subjectLooks) {
            foreach ($subjectLooks as $look) {
                [$label, $cost, $params] = $looks[$look];

                Style::updateOrCreate(
                    ['subject' => $subject, 'look' => $look],
                    [
                        'name' => $label,
                        'credit_cost' => $cost,
                        'provider_params' => $params + ['look' => $look],
                        'active' => true,
                    ],
                );
            }
        }
    }
}
