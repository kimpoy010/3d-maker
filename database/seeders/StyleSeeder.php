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

        // What each look means, per subject. Sent to the model provider.
        $prompts = [
            'person.realistic' => 'A lifelike 3D bust of a person with natural skin, hair and clothing, faithful to the photo, soft matte finish.',
            'person.cartoon' => 'A stylized cartoon figurine of a person with smooth rounded shapes, bold clean colours and slightly exaggerated features, glossy toy finish.',
            'person.clay' => 'A hand-sculpted polymer clay figurine of a person with simple chunky forms, soft fingerprint texture and a matte finish.',
            'person.chibi' => 'A chibi collectible figure of a person with an oversized head, a tiny body, big expressive eyes and a smooth glossy vinyl finish, standing on a small round base.',
            'pet.realistic' => 'A lifelike 3D model of a pet with detailed fur, natural proportions and the colours shown in the photo.',
            'pet.cartoon' => 'A cute cartoon figurine of a pet with a rounded body, big eyes and bold simple colours, glossy toy finish.',
            'pet.clay' => 'A hand-sculpted polymer clay figurine of a pet with simple chunky forms, visible tool marks and a soft matte surface.',
            'object.realistic' => 'An accurate 3D model of the object with true materials, colours and fine surface detail.',
            'object.clay' => 'A handmade clay miniature of the object with simplified shapes and a soft matte finish.',
        ];

        foreach ($matrix as $subject => $subjectLooks) {
            foreach ($subjectLooks as $look) {
                [$label, $cost, $params] = $looks[$look];

                Style::updateOrCreate(
                    ['subject' => $subject, 'look' => $look],
                    [
                        'name' => $label,
                        'prompt' => $prompts["{$subject}.{$look}"],
                        'credit_cost' => $cost,
                        'provider_params' => $params + ['look' => $look],
                        'active' => true,
                    ],
                );
            }
        }
    }
}
