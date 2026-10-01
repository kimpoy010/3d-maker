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
        ->and(strtolower($person['chibi']))->toContain('chibi');
});

it('offers a sleepy look for people and pets', function () {
    $this->seed(StyleSeeder::class);

    $sleepy = Style::where('look', 'sleepy')->orderBy('subject')->get();

    expect($sleepy->pluck('subject')->map->value->all())->toBe(['person', 'pet'])
        ->and($sleepy->every(fn (Style $style) => str_contains($style->prompt, 'sleepy')))->toBeTrue()
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
        ->toContain('1:6 scale')
        ->toContain('matte')
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

it('describes the person chibi as a big-head vinyl toy with printable hair', function () {
    $this->seed(StyleSeeder::class);

    $prompt = strtolower(Style::where('subject', 'person')->where('look', 'chibi')->value('prompt'));

    expect($prompt)
        ->toContain('identity anchor')
        ->toContain('do not beautify')
        ->toContain('no visible neck')
        ->toContain('directly on top of the tiny shoulders')
        ->toContain('oversized square-shaped head')
        ->toContain('solid black glossy button eyes')
        ->toContain('low plain round base')
        ->toContain('no individual strands')
        ->toContain('no flyaway hairs')
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
        ->toContain('rope-like locks')
        ->toContain('no thin strands')
        ->toContain('no flyaway hairs')
        ->toContain('3d-printable')
        ->not->toContain('vinyl')
        ->not->toContain('button eyes')
        ->not->toContain('funko');
});
