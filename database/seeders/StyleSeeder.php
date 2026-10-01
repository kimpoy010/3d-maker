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
            'sleepy' => ['Sleepy', 5, ['texture' => true, 'target_faces' => 20000]],
        ];

        $matrix = [
            Subject::Person->value => ['realistic', 'cartoon', 'clay', 'chibi', 'sleepy'],
            Subject::Pet->value => ['realistic', 'cartoon', 'clay', 'sleepy'],
            Subject::Object->value => ['realistic', 'clay'],
        ];

        // What each look means, per subject. Sent to the model provider.
        $prompts = [
            'person.realistic' => 'A lifelike 3D bust of a person with natural skin, hair and clothing, faithful to the photo, soft matte finish.',
            'person.cartoon' => 'A stylized cartoon figurine of a person with smooth rounded shapes, bold clean colours and slightly exaggerated features, glossy toy finish.',
            'person.clay' => 'Transform the person in this photo into a cute handmade polymer clay miniature. Keep their recognizable hairstyle, hair colour, skin tone, glasses or accessories and outfit colours. Stylized proportions with a large head, simple small dot eyes and rosy cheeks. Smooth soft-matte sculpted clay with a slight satin sheen, and tiny hand-sculpted details on the clothing and accessories such as piping, buttons or small flowers. Simple standing pose with the limbs clearly separate from the body. Full body in frame on a small plain round base with no text. Plain seamless light studio background, soft even lighting, no props, portrait 3:4.',
            'person.chibi' => 'Transform the person in this photo into a collectible chibi vinyl toy figure with an oversized round head about half the height of the figure and a small narrow body. Face: tiny round glossy black bead eyes, a small simple nose, a minimal mouth and soft blush. Hair: sculpted in thick glossy strands that keep their hairstyle and colour. Keep their glasses, jewellery, clothing and accessories as simplified sculpted details. Relaxed standing pose with the head slightly tilted and a hand in a pocket or at the side. Smooth satin-gloss vinyl finish. Full body in frame on a low plain round black base with no text. Plain seamless white studio background, soft even lighting, no props, portrait 3:4.',
            'person.sleepy' => 'Transform the person in this photo into a dreamy sleepy designer figure. Keep their recognizable face shape, hairstyle, hair colour, skin tone, glasses or accessories and outfit. Heavy half-lidded eyes, a small nose, soft blush on the cheeks, the head tilted slightly down and a calm drowsy expression with no dark eye circles. Large head, small soft body, muted pastel palette. Matte slightly chalky resin-clay finish with subtle hand-finished imperfections, not glossy. Standing pose with hands relaxed in pockets or loosely clasped, limbs clearly separate from the body. Full body in frame on a small plain round base with no text. Plain seamless off-white studio background, soft even lighting, no props, portrait 3:4.',
            'pet.realistic' => 'A lifelike 3D model of a pet with detailed fur, natural proportions and the colours shown in the photo.',
            'pet.cartoon' => 'A cute cartoon figurine of a pet with a rounded body, big eyes and bold simple colours, glossy toy finish.',
            'pet.clay' => 'A hand-sculpted polymer clay figurine of a pet with simple chunky forms, visible tool marks and a soft matte surface.',
            'pet.sleepy' => 'Transform the pet in this photo into a dreamy sleepy designer figure. Keep its fur colours and markings. Heavy half-lidded eyes, a small nose, soft blush, the head tilted slightly down and a calm drowsy expression. Large head, small soft rounded body, muted pastel palette. Matte slightly chalky resin-clay finish with subtle hand-finished imperfections, not glossy. Simple sitting or standing pose with the legs clearly separate from the body. Full body in frame on a small plain round base with no text. Plain seamless off-white studio background, soft even lighting, no props, portrait 3:4.',
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
