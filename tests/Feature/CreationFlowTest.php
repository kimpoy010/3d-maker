<?php

use App\Enums\CreationStatus;
use App\Enums\LedgerReason;
use App\Jobs\GenerateCreation;
use App\Models\Creation;
use App\Models\Style;
use App\Models\User;
use App\Services\Credits\CreditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    app(CreditService::class)->grant($this->user, 20, LedgerReason::Signup);
    $this->style = Style::factory()->create(['credit_cost' => 5]);
});

it('requires authentication', function () {
    $this->get('/create')->assertRedirect('/login');
    $this->post('/creations')->assertRedirect('/login');
});

it('lists active styles and the balance on the create page', function () {
    Style::factory()->create(['active' => false]);

    $this->actingAs($this->user)->get('/create')->assertInertia(fn (Assert $page) => $page
        ->component('Create')
        ->has('styles', 1)
        ->where('styles.0.id', $this->style->id)
        ->where('balance', 20));
});

it('creates a queued creation, charges credits, and dispatches the job', function () {
    Queue::fake();

    $response = $this->actingAs($this->user)->post('/creations', [
        'photo' => UploadedFile::fake()->image('me.jpg', 800, 800),
        'style_id' => $this->style->id,
    ]);

    $creation = Creation::firstOrFail();
    $response->assertRedirect("/creations/{$creation->id}");
    expect($creation->status)->toBe(CreationStatus::Queued)
        ->and($creation->user_id)->toBe($this->user->id)
        ->and($creation->cost_credits)->toBe(5)
        ->and(app(CreditService::class)->balance($this->user))->toBe(15);
    Storage::disk('local')->assertExists($creation->source_image_path);
    Queue::assertPushed(GenerateCreation::class, fn ($job) => $job->creationId === $creation->id);
});

it('runs end to end with the mock provider', function () {
    config(['queue.default' => 'sync', 'models.mock.delay_seconds' => 0, 'models.mock.fail_rate' => 0]);

    $this->actingAs($this->user)->post('/creations', [
        'photo' => UploadedFile::fake()->image('me.jpg', 800, 800),
        'style_id' => $this->style->id,
    ]);

    $creation = Creation::firstOrFail();
    expect($creation->status)->toBe(CreationStatus::Succeeded);
    Storage::disk('local')->assertExists($creation->model_path);
});

it('rejects a submission the user cannot afford', function () {
    Queue::fake();
    $expensive = Style::factory()->create(['credit_cost' => 50]);

    $this->actingAs($this->user)->post('/creations', [
        'photo' => UploadedFile::fake()->image('me.jpg', 800, 800),
        'style_id' => $expensive->id,
    ])->assertSessionHasErrors('style_id');

    expect(Creation::count())->toBe(0)
        ->and(app(CreditService::class)->balance($this->user))->toBe(20);
    Queue::assertNothingPushed();
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('validates the photo and style', function (array $payload, string $field) {
    Queue::fake();

    $this->actingAs($this->user)->post('/creations', $payload + [
        'photo' => UploadedFile::fake()->image('me.jpg', 800, 800),
        'style_id' => $this->style->id,
    ])->assertSessionHasErrors($field);

    expect(Creation::count())->toBe(0);
})->with([
    'not an image' => [['photo' => UploadedFile::fake()->create('x.txt', 10, 'text/plain')], 'photo'],
    'too small' => [['photo' => UploadedFile::fake()->image('s.jpg', 100, 100)], 'photo'],
    'too large' => [['photo' => UploadedFile::fake()->image('b.jpg', 800, 800)->size(11000)], 'photo'],
    'too many pixels' => [['photo' => UploadedFile::fake()->image('p.jpg', 8001, 600)], 'photo'],
    'missing style' => [['style_id' => null], 'style_id'],
    'unknown style' => [['style_id' => 9999], 'style_id'],
]);

it('rejects inactive styles', function () {
    Queue::fake();
    $inactive = Style::factory()->create(['active' => false]);

    $this->actingAs($this->user)->post('/creations', [
        'photo' => UploadedFile::fake()->image('me.jpg', 800, 800),
        'style_id' => $inactive->id,
    ])->assertSessionHasErrors('style_id');
});
