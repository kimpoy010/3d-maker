<?php

use App\Enums\CreationStatus;
use App\Enums\Subject;
use App\Models\Creation;
use App\Models\Style;
use App\Models\User;
use Database\Seeders\StyleSeeder;

it('casts style attributes', function () {
    $style = Style::factory()->create(['subject' => Subject::Pet, 'provider_params' => ['texture' => true]]);

    $fresh = Style::find($style->id);

    expect($fresh->subject)->toBe(Subject::Pet)
        ->and($fresh->provider_params)->toBe(['texture' => true])
        ->and($fresh->active)->toBeTrue();
});

it('filters active styles', function () {
    Style::factory()->create();
    Style::factory()->create(['active' => false]);

    expect(Style::active()->count())->toBe(1);
});

it('creates a creation that belongs to a user and style', function () {
    $creation = Creation::factory()->create();

    expect($creation->status)->toBe(CreationStatus::Queued)
        ->and($creation->user)->toBeInstanceOf(User::class)
        ->and($creation->style)->toBeInstanceOf(Style::class)
        ->and($creation->status->isFinished())->toBeFalse();
});

it('treats succeeded and failed as finished', function () {
    expect(CreationStatus::Succeeded->isFinished())->toBeTrue()
        ->and(CreationStatus::Failed->isFinished())->toBeTrue()
        ->and(CreationStatus::Processing->isFinished())->toBeFalse();
});

it('seeds styles idempotently', function () {
    $this->seed(StyleSeeder::class);
    $count = Style::count();
    $this->seed(StyleSeeder::class);

    expect($count)->toBeGreaterThan(5)->and(Style::count())->toBe($count);
});

it('stores an optional prompt on a style', function () {
    $with = Style::factory()->create(['prompt' => 'glossy vinyl figure']);
    $without = Style::factory()->create(['prompt' => null]);

    expect(Style::find($with->id)->prompt)->toBe('glossy vinyl figure')
        ->and(Style::find($without->id)->prompt)->toBeNull();
});

it('seeds a non-empty prompt for every style', function () {
    $this->seed(StyleSeeder::class);

    $styles = Style::all();

    expect($styles)->not->toBeEmpty()
        ->and($styles->every(fn (Style $style) => filled($style->prompt)))->toBeTrue();
});

it('writes different prompts for different looks', function () {
    $this->seed(StyleSeeder::class);

    $person = Style::where('subject', 'person')->pluck('prompt', 'look');

    expect($person['chibi'])->not->toBe($person['clay'])
        ->and(strtolower($person['chibi']))->toContain('pop vinyl');
});

it('offers a sleepy look for people and pets', function () {
    $this->seed(StyleSeeder::class);

    $sleepy = Style::where('look', 'sleepy')->orderBy('subject')->get();

    expect($sleepy->pluck('subject')->map->value->all())->toBe(['person', 'pet'])
        ->and($sleepy->every(fn (Style $style) => $style->credit_cost === 5))->toBeTrue();
});

it('asks figure styles for a full-body shot on a plain background', function () {
    $this->seed(StyleSeeder::class);

    $figures = Style::whereIn('look', ['chibi', 'sleepy', 'clay'])
        ->where(fn ($query) => $query->where('subject', 'person')->orWhere('look', 'sleepy'))
        ->get();

    expect($figures)->toHaveCount(4)
        ->and($figures->every(fn (Style $style) => str_contains(strtolower($style->prompt), 'full body')))->toBeTrue()
        ->and($figures->every(fn (Style $style) => str_contains(strtolower($style->prompt), 'plain seamless')))->toBeTrue()
        ->and($figures->every(fn (Style $style) => str_contains($style->prompt, 'no text')))->toBeTrue();
});

it('keeps third-party brand names out of style prompts', function () {
    $this->seed(StyleSeeder::class);

    foreach (Style::all() as $style) {
        expect(strtolower($style->prompt))->not->toContain('funko')->not->toContain('pop figure')->not->toContain('mukha');
    }
});

it('keeps the person sleepy prompt generic and printable', function () {
    $this->seed(StyleSeeder::class);

    $prompt = strtolower(Style::where('subject', 'person')->where('look', 'sleepy')->value('prompt'));

    expect($prompt)
        ->toContain('1:6 designer vinyl figure')
        ->toContain('no individual strands')
        ->toContain('no flyaway hairs')
        ->toContain('3d-printable')
        ->not->toContain('auburn')
        ->not->toContain('hooded')
        ->not->toContain('beige')
        ->not->toContain('heart')
        ->not->toContain('hoop')
        ->not->toContain('11 inches');
});

it('describes the person chibi as a big-head pop vinyl toy with printable hair', function () {
    $this->seed(StyleSeeder::class);

    $prompt = strtolower(Style::where('subject', 'person')->where('look', 'chibi')->value('prompt'));

    expect($prompt)
        ->toContain('big-head pop vinyl')
        ->toContain('oversized square-shaped head')
        ->toContain('about half of the total figure height')
        ->toContain('no visible neck')
        ->toContain('very short torso')
        ->toContain('solid black glossy round eyes')
        ->toContain('only about half as wide as the head')
        ->toContain('baggy, dark charcoal wide-leg trousers')
        ->toContain('plain dark round base')
        ->toContain('no individual strands')
        ->toContain('no flyaway hairs')
        ->toContain('no box, no packaging, no props')
        ->toContain('3d-printable')
        ->not->toContain('auburn')
        ->not->toContain('hooded')
        ->not->toContain('funko');
});

it('describes the person clay as a hand-modelled fondant figure, not a vinyl toy', function () {
    $this->seed(StyleSeeder::class);

    $prompt = strtolower(Style::where('subject', 'person')->where('look', 'clay')->value('prompt'));

    expect($prompt)
        ->toContain('identity anchor')
        ->toContain('fondant')
        ->toContain('sugarpaste')
        ->toContain('hand-modelled')
        ->toContain('natural, proportionate')
        ->toContain('five heads tall')
        ->toContain('not a chibi')
        ->not->toContain('large rounded head')
        ->not->toContain('oversized')
        ->toContain('rope-like locks')
        ->toContain('no thin strands')
        ->toContain('no flyaway hairs')
        ->toContain('3d-printable')
        ->not->toContain('vinyl')
        ->not->toContain('button eyes')
        ->not->toContain('funko');
});

/** Keys ("subject.look") of the styles whose lowercased prompt fails the given check. */
function stylesFailing(callable $check, ?array $subjects = null): array
{
    return Style::all()
        ->when($subjects, fn ($styles) => $styles->filter(fn (Style $style) => in_array($style->subject->value, $subjects, true)))
        ->reject(fn (Style $style) => $check(strtolower($style->prompt)))
        ->map(fn (Style $style) => "{$style->subject->value}.{$style->look}")
        ->values()
        ->all();
}

it('gives every style a complete, printable, photo-anchored prompt', function () {
    $this->seed(StyleSeeder::class);

    expect(Style::count())->toBe(11)
        ->and(stylesFailing(fn (string $p) => str_contains($p, '3d-printable')))->toBe([])
        ->and(stylesFailing(fn (string $p) => str_contains($p, 'plain seamless')))->toBe([])
        ->and(stylesFailing(fn (string $p) => str_contains($p, 'no text')))->toBe([])
        ->and(stylesFailing(fn (string $p) => str_contains($p, 'identity anchor') || str_contains($p, 'provided photo') || str_contains($p, 'attached image')))->toBe([])
        ->and(Style::pluck('prompt')->unique())->toHaveCount(11);
});

it('asks people and pets for thick sculpted hair or fur', function () {
    $this->seed(StyleSeeder::class);

    expect(stylesFailing(fn (string $p) => str_contains($p, 'no flyaway hairs'), ['person', 'pet']))->toBe([]);
});

it('keeps pets and objects sturdy and printable', function () {
    $this->seed(StyleSeeder::class);

    expect(stylesFailing(fn (string $p) => str_contains($p, 'tail'), ['pet']))->toBe([])
        ->and(stylesFailing(fn (string $p) => str_contains($p, 'at least 2 mm'), ['object']))->toBe([])
        ->and(stylesFailing(fn (string $p) => str_contains($p, 'readable text'), ['object']))->toBe([]);
});

it('keeps the person sleepy figure on a plain background with no packaging or lettering', function () {
    $this->seed(StyleSeeder::class);

    $prompt = strtolower(Style::where('subject', 'person')->where('look', 'sleepy')->value('prompt'));

    expect($prompt)
        ->toContain('plain seamless off-white studio background')
        ->toContain('no box, no packaging, no props')
        ->toContain('no text, logos or lettering anywhere')
        ->toContain('full body in frame on a small plain round base');
});

it('asks the person sleepy figure for squat chibi proportions', function () {
    $this->seed(StyleSeeder::class);

    $prompt = strtolower(Style::where('subject', 'person')->where('look', 'sleepy')->value('prompt'));

    expect($prompt)->toContain('oversized head about 40 to 45 percent')->toContain('short legs');
});
