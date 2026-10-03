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
            'realistic' => ['Realistic', 50, ['texture' => true, 'target_faces' => 100000]],
            'cartoon' => ['Cartoon', 50, ['texture' => true, 'target_faces' => 100000]],
            'clay' => ['Clay', 50, ['texture' => false, 'target_faces' => 100000]],
            'chibi' => ['Chibi', 50, ['texture' => true, 'target_faces' => 100000]],
            'sleepy' => ['Sleepy', 50, ['texture' => true, 'target_faces' => 100000]],
        ];

        $matrix = [
            Subject::Person->value => ['realistic', 'cartoon', 'clay', 'chibi', 'sleepy'],
            Subject::Pet->value => ['realistic', 'cartoon', 'clay', 'sleepy'],
            Subject::Object->value => ['realistic', 'clay'],
        ];

        // What each look means, per subject. Sent to the model provider.
        $prompts = [
            'person.realistic' => <<<'PROMPT'
Reference contract: Use the uploaded image as the identity anchor for the subject. Preserve the subject's exact recognizable likeness, face shape, eye spacing, nose shape, skin tone, hairline, hair texture and outfit from the photo. Do not beautify or alter the core identity.

Transform this subject into a high-end 1:6 scale collectible figure with realistic proportions, like a hand-painted museum-quality resin statuette.

FIGURE DETAILS:
- Proportions: natural proportions as in the photo, with a realistic head-to-body ratio, sturdy rather than slender limbs and a realistic face with a calm natural expression. Not a chibi and not a toy.
- Material: matte hand-painted resin with subtle natural skin tones, a soft satin finish on clothing, clean paint lines and minimal seams.
- Hair: the hairstyle and colour from the photo, sculpted as thick, smooth clumps with rounded tips. No individual strands, no flyaway hairs and no fine grooves or fibres.
- Clothing: the outfit from the photo in its original colours, as smooth sculpted folds in solid material.
- Accessories: glasses, jewellery and other accessories as chunky simplified sculpted forms in their original colours.

SETTING & STYLE:
- Relaxed standing pose with the arms close to the body, full body in frame, on a low plain round base with no text.
- Plain seamless light grey studio background, soft even lighting with a gentle shadow beneath the figure.
- Photorealistic photograph of a collectible figure, 9:16 vertical aspect ratio.

PRINTING: Design it to be 3D-printable: nothing thinner than about 2 mm at figure scale, no floating or hanging parts, feet fused to the base, arms close to the body.
PROMPT,
            'person.cartoon' => <<<'PROMPT'
Reference contract: Use the uploaded image as the identity anchor for the subject. Preserve the subject's recognizable likeness, face shape, skin tone, hair colour, hairstyle and outfit from the photo. Do not beautify or alter the core identity.

Transform this subject into a stylized cartoon collectible figure, like a premium designer toy with bold, clean, simplified shapes.

FIGURE DETAILS:
- Proportions: stylized cartoon proportions with the head about one quarter of the total height, a simplified rounded body, slightly exaggerated features, big expressive eyes with a bright highlight and a small friendly smile.
- Material: smooth glossy painted vinyl with bold clean colours and crisp thick painted lines, minimal seams.
- Hair: the hairstyle and colour from the photo, sculpted as one smooth thick shape with a few large clumps and rounded tips. No individual strands, no flyaway hairs and no fine grooves or fibres.
- Clothing: the outfit from the photo in its original colours, simplified into bold sculpted forms.
- Accessories: glasses, jewellery and other accessories as chunky simplified sculpted forms in their original colours.

SETTING & STYLE:
- Cheerful standing pose, full body in frame, on a low plain round base with no text.
- Plain seamless white studio background, soft even lighting with a gentle shadow beneath the figure.
- Photorealistic photograph of a glossy toy figure, 9:16 vertical aspect ratio.

PRINTING: Design it to be 3D-printable: nothing thinner than about 2 mm at figure scale, no floating or hanging parts, feet fused to the base, arms close to the body.
PROMPT,
            'person.clay' => <<<'PROMPT'
Reference contract: Use the uploaded image as the identity anchor for the subject. Preserve the subject's recognizable likeness, face shape, skin tone, hair colour, hairstyle and outfit from the photo. Do not beautify or alter the core identity.

Transform this subject into a handmade fondant-clay figure, like a hand-modelled sugarpaste cake-topper miniature crafted from soft polymer clay.

FIGURE DETAILS:
- Proportions: natural, proportionate doll-like anatomy, about five heads tall: the head is about one fifth of the total height, with normal shoulders, a slim but sturdy body and normal-length arms and legs. Not a chibi, not a bobblehead, not a toy with an enlarged head. The face is simple and sweet: small black dot eyes, a tiny nose, a small gentle smile and soft rosy cheeks.
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
Using the attached image as reference, I want you to generate a hyper-realistic 1:6 designer vinyl figure in a classic big-head pop vinyl art style. Proportions: an oversized square-shaped head with rounded corners, about half of the total figure height, sitting directly on a very short torso with no visible neck. The torso is only about one fifth of the total height and the legs are short but clearly visible, about 30 percent of the total height, with the feet showing. The body is small and narrow: the shoulders and torso are only about half as wide as the head, with short legs and small feet. If the photo does not show the legs, give the figure loose, baggy, dark charcoal wide-leg trousers, otherwise follow the clothing in the photo; keep the shoe colours from the photo. Face: large solid black glossy round eyes with a small white highlight and neat lashes, a tiny pointed nose and a tiny closed mouth. Pose: hands inside the pockets if the clothing has pockets, otherwise short arms hanging close to the body. Keep the person's recognizable face shape, skin tone, hair colour, hairstyle, glasses, jewellery and clothing colours from the photo. Sculpt the hair as a few thick smooth clumps with broad flowing sections and rounded tips, like carved vinyl: no individual strands, no flyaway hairs, no fine grooves or fibres. Full body in frame on a small plain dark round base with no text. Plain seamless off-white studio background, soft even lighting. No box, no packaging, no props, no shelves, no desk, and no text, logos or lettering anywhere in the image, including on the base. Design it to be 3D-printable: nothing thinner than about 2 mm at figure scale, no floating or hanging parts, feet fused to the base, arms close to the body.
PROMPT,
            'person.sleepy' => 'Using the attached image as reference, I want you to generate a hyper-realistic 1:6 designer vinyl figure with Hirono-like pop art style. Chibi proportions: an oversized head about 40 to 45 percent of the total figure height, a small compact body and short legs, so the whole figure is only about two and a half heads tall. Sculpt the hair as a few thick smooth clumps with broad flowing sections and rounded tips, like carved vinyl: no individual strands, no flyaway hairs, no fine grooves or fibres. Full body in frame on a small plain round base. Plain seamless off-white studio background, soft even lighting. No box, no packaging, no props, no shelves, no desk, and no text, logos or lettering anywhere in the image, including on the base. Design it to be 3D-printable: nothing thinner than about 2 mm at figure scale, no floating or hanging parts, feet fused to the base, arms close to the body.',
            'pet.realistic' => <<<'PROMPT'
Reference contract: Use the uploaded image as the identity anchor for the pet. Preserve its exact recognizable markings, fur colours and patterns, ear shape, muzzle shape, eye colour and body proportions. Do not beautify or alter the core identity.

Transform this pet into a high-end 1:6 scale collectible animal figure with realistic proportions, like a hand-painted museum-quality resin statuette.

FIGURE DETAILS:
- Proportions: the natural anatomy and proportions of this animal as in the photo, with a calm natural expression. Not a chibi and not a toy.
- Material: matte hand-painted resin with a soft satin finish, clean paint lines and minimal seams.
- Fur: sculpted as thick, smooth tufts and clumps that follow the direction of the fur, with rounded tips. No individual hairs, no flyaway hairs and no fine grooves or fibres. No whiskers, or only a few short chunky whiskers fused to the muzzle.
- Accessories: a collar, tag or bow, if present, as a chunky simplified sculpted form in its original colours.

SETTING & STYLE:
- A natural simple pose, sitting or standing, whole animal in frame (full body), on a low plain round base with no text.
- Plain seamless light grey studio background, soft even lighting with a gentle shadow beneath the figure.
- Photorealistic photograph of a collectible figure, 9:16 vertical aspect ratio.

PRINTING: Design it to be 3D-printable: nothing thinner than about 2 mm at figure scale, no floating or hanging parts, paws fused to the base, ears thick and rounded, the tail thick and curled against the body or the base.
PROMPT,
            'pet.cartoon' => <<<'PROMPT'
Reference contract: Use the uploaded image as the identity anchor for the pet. Preserve its recognizable markings, fur colours and patterns, ear shape and eye colour from the photo. Do not alter the core identity.

Transform this pet into a cute stylized cartoon collectible figure, like a premium designer toy with bold, clean, simplified shapes.

FIGURE DETAILS:
- Proportions: a rounded body, the head about one third of the total height, big shiny eyes with a bright highlight, a small nose and a happy expression.
- Material: smooth glossy painted vinyl with bold clean colours and crisp thick painted lines, minimal seams.
- Fur: simplified into smooth thick tufts with rounded tips and clean painted markings. No individual hairs, no flyaway hairs and no fine grooves or fibres. No whiskers.
- Accessories: a collar, tag or bow, if present, as a chunky simplified sculpted form in its original colours.

SETTING & STYLE:
- A cheerful sitting or standing pose, whole animal in frame (full body), on a low plain round base with no text.
- Plain seamless white studio background, soft even lighting with a gentle shadow beneath the figure.
- Photorealistic photograph of a glossy toy figure, 9:16 vertical aspect ratio.

PRINTING: Design it to be 3D-printable: nothing thinner than about 2 mm at figure scale, no floating or hanging parts, paws fused to the base, ears thick and rounded, the tail thick and curled against the body or the base.
PROMPT,
            'pet.clay' => <<<'PROMPT'
Reference contract: Use the uploaded image as the identity anchor for the pet. Preserve its recognizable markings, fur colours and patterns, ear shape, muzzle shape and eye colour from the photo. Do not alter the core identity.

Transform this pet into a handmade fondant-clay figure, like a hand-modelled sugarpaste cake-topper miniature crafted from soft polymer clay.

FIGURE DETAILS:
- Proportions: natural, proportionate animal anatomy as in the photo, with a sweet simple face: small black dot eyes, a small nose and a gentle expression. Not a chibi and not a bobblehead.
- Material: smooth, soft, matte sugarpaste-like clay with a gentle satin sheen, rounded forms and soft colour gradients. Hand-modelled softness, no fingerprint texture, no glossy plastic.
- Fur: modelled as thick, soft, rounded tufts and rope-like locks that follow the direction of the fur. No thin strands, no flyaway hairs and no whiskers.
- Details: a few tiny hand-modelled decorations such as a collar, a small bow or little flowers, kept small but chunky.

SETTING & STYLE:
- A natural simple pose, sitting or standing, whole animal in frame (full body), on a small plain round clay base with no text.
- Clean plain seamless light studio background, soft even lighting with a gentle shadow beneath the figure.
- Photorealistic macro photograph of a handmade clay miniature, 9:16 vertical aspect ratio.

PRINTING: Design it to be 3D-printable: nothing thinner than about 2 mm at figure scale, no floating or hanging parts, paws fused to the base, ears thick and rounded, the tail thick and curled against the body or the base.
PROMPT,
            'pet.sleepy' => <<<'PROMPT'
Generate a hyper-realistic photograph of a high-end designer collectible vinyl art toy figure in 1:6 scale, based on the pet in the provided photo. The figure is a chibi-style version of that pet: keep its recognizable markings, fur colours and patterns, ear shape and eye colour. Calm, slightly sleepy expression with relaxed heavy eyelids, the head tilted slightly down. Made of matte, slightly textured vinyl plastic with clean, thick paint lines and minimal seams. Large head, small soft rounded body. Pose: sitting calmly or curled up, with the legs clearly separate from the body. Sculpt the fur as a few thick smooth tufts with broad flowing sections and rounded tips, like carved vinyl: no individual hairs, no flyaway hairs, no fine grooves or fibres, and no whiskers. Render a collar or accessory, if present, as a chunky sculpted form in its original colours. Full body in frame on a small plain round base with no text. Plain seamless off-white studio background, soft even lighting, no props, portrait 3:4. Design it to be 3D-printable: nothing thinner than about 2 mm at figure scale, no floating or hanging parts, paws fused to the base, ears thick and rounded, the tail thick and curled against the body or the base.
PROMPT,
            'object.realistic' => <<<'PROMPT'
Reference contract: Use the uploaded image as the identity anchor for the object. Preserve its exact shape, silhouette, proportions, colours, materials and distinctive details. Do not redesign it.

Transform this object into an accurate hand-painted miniature scale model, like a museum-quality resin replica.

OBJECT DETAILS:
- Shape: faithful proportions and silhouette. Simplify tiny details. Thin parts such as handles, straps, antennas, cords and legs are made thick and sturdy, with walls and parts at least 2 mm thick at model scale. Close any hollow openings that would be fragile.
- Material: matte hand-painted resin in the object's true colours, with a soft satin finish where the original is shiny, clean paint lines and minimal seams.
- Text and logos: omit them or turn them into plain simple shapes. No readable text.

SETTING & STYLE:
- The object rests stably on a low plain round base with no text, whole object in frame.
- Plain seamless light grey studio background, soft even lighting with a gentle shadow beneath the model.
- Photorealistic photograph of a collectible miniature, 9:16 vertical aspect ratio.

PRINTING: Design it to be 3D-printable: nothing thinner than at least 2 mm at model scale, no floating or hanging parts, no thin protruding pieces, fused solidly to the base.
PROMPT,
            'object.clay' => <<<'PROMPT'
Reference contract: Use the uploaded image as the identity anchor for the object. Preserve its recognizable shape, silhouette, proportions and colours. Do not redesign it.

Transform this object into a handmade fondant-clay miniature, like a hand-modelled sugarpaste cake-topper crafted from soft polymer clay.

OBJECT DETAILS:
- Shape: faithful proportions, simplified into smooth rounded forms. Thin parts such as handles, straps and legs are made thick and sturdy, with parts at least 2 mm thick at model scale.
- Material: smooth, soft, matte sugarpaste-like clay with a gentle satin sheen and soft colour gradients. Hand-modelled softness, no fingerprint texture, no glossy plastic.
- Details: a few tiny hand-modelled decorative details such as piping, dots or small flowers, kept small but chunky.
- Text and logos: omit them or turn them into plain simple shapes. No readable text.

SETTING & STYLE:
- The object rests stably on a small plain round clay base with no text, whole object in frame.
- Clean plain seamless light studio background, soft even lighting with a gentle shadow beneath the model.
- Photorealistic macro photograph of a handmade clay miniature, 9:16 vertical aspect ratio.

PRINTING: Design it to be 3D-printable: nothing thinner than at least 2 mm at model scale, no floating or hanging parts, no thin protruding pieces, fused solidly to the base.
PROMPT,
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
