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
            'person.clay' => <<<'PROMPT'
Reference contract: Use the uploaded image as the identity anchor for the subject. Preserve the subject's recognizable likeness, face shape, skin tone, hair colour, hairstyle and outfit from the photo. Do not beautify or alter the core identity.

Transform this subject into a handmade fondant-clay figure, like a hand-modelled sugarpaste cake-topper miniature crafted from soft polymer clay.

FIGURE DETAILS:
- Proportions: a charming storybook figure with a large rounded head about one third of the total height, a small slender body, simple small black dot eyes, a tiny nose, a small gentle smile and soft rosy cheeks.
- Material: smooth, soft, matte sugarpaste-like clay with a gentle satin sheen, rounded forms and soft colour gradients. Hand-modelled softness, no fingerprint texture, no glossy plastic.
- Hair: the hairstyle and colour from the photo, modelled as thick, smooth, rope-like locks with broad rounded sections. No thin strands and no flyaway hairs.
- Clothing: the outfit from the photo in its original colours, as smooth folded clay with a few tiny hand-modelled decorative details such as piping, buttons, small flowers or beads, kept small but chunky.
- Accessories: glasses, jewellery and other accessories as chunky simplified clay forms in their original colours.

SETTING & STYLE:
- Simple standing pose, full body in frame, on a small plain round clay base with no text.
- Clean plain seamless light studio background, soft even lighting with a gentle shadow beneath the figure.
- Photorealistic macro photograph of a handmade clay miniature, 9:16 vertical aspect ratio.

PRINTING: Design it to be 3D-printable: nothing thinner than about 2 mm at figure scale, no floating or hanging parts, feet fused to the base, arms close to the body.
PROMPT,
            'person.chibi' => <<<'PROMPT'
Reference contract: Use the uploaded image as the identity anchor for the subject. Preserve the subject's exact recognizable likeness, face shape, eye spacing, nose shape, skin tone, hairline, hair texture and outfit from the photo. Do not beautify or alter the core identity.

Transform this subject into a 3D hyper-realistic stylized collectible vinyl toy figure in the style of an oversized-head designer vinyl collectible.

FIGURE DETAILS (CRITICAL ANATOMY):
- Anatomy: the figure has no visible neck. The oversized head sits directly on top of the tiny shoulders, with the chin completely hiding any neck joint.
- Chibi proportions: an oversized square-shaped head with rounded corners, an incredibly tiny body, and large, solid black glossy button eyes.
- Replicate the exact hair style, hair colour and facial hair (if applicable) from the reference image, rendered as smooth vinyl: one thick sculpted shape with a few large clumps and rounded tips, with no individual strands, no flyaway hairs and no fine grooves or fibres.
- Replicate the exact clothing, shoes and distinct accessories from the photo, in their original colours, as simplified chunky sculpted forms.

SETTING & STYLE:
- The figure stands independently, full body in frame, on a low plain round base with no text.
- Clean, solid, minimalist plain seamless studio background.
- Soft, professional studio lighting that casts a gentle shadow beneath the figure.
- Photorealistic matte and glossy vinyl textures, 9:16 vertical aspect ratio.

PRINTING: Design it to be 3D-printable: nothing thinner than about 2 mm at figure scale, no floating or hanging parts, feet fused to the base, arms close to the body.
PROMPT,
            'person.sleepy' => 'Generate a hyper-realistic photograph of a high-end designer collectible vinyl art toy figure in 1:6 scale, based on the person in the provided photo. The figure is a chibi-style version of that person: keep their recognizable face shape, skin tone, hair colour, hair length and hairstyle, and any glasses or accessories. Calm, neutral, slightly sleepy expression with relaxed heavy eyelids. Made of matte, slightly textured vinyl plastic with clean, thick paint lines and minimal seams. Pose: standing facing forward in a relaxed posture, with the hands in the pockets if the clothing has pockets, otherwise relaxed at the sides. Attire: the clothing from the photo in its original colours, rendered as sculpted, solid plastic forms. Render glasses, jewellery and other accessories as chunky sculpted forms in their original colours. Sculpt the hair as a few thick smooth clumps with broad flowing sections and rounded tips, like carved vinyl: no individual strands, no flyaway hairs, no fine grooves or fibres. Full body in frame on a small plain round base with no text. Plain seamless off-white studio background, soft even lighting, no props, portrait 3:4. Design it to be 3D-printable: nothing thinner than about 2 mm at figure scale, no floating or hanging parts, feet fused to the base, arms close to the body.',
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
