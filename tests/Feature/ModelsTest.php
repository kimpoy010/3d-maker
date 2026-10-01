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
