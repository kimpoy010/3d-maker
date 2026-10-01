<?php

use App\Enums\CreationStatus;
use App\Enums\LedgerReason;
use App\Enums\StylizationStatus;
use App\Models\Creation;
use App\Models\Style;
use App\Models\Stylization;
use App\Models\User;
use App\Services\Credits\CreditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    config(['credits.restyle_cost' => 1, 'stylizer.enabled' => true, 'stylizer.daily_limit' => 30]);
    $this->user = User::factory()->create();
    app(CreditService::class)->grant($this->user, 20, LedgerReason::Signup);
    $this->style = Style::factory()->create(['credit_cost' => 5]);
});

it('requires authentication', function () {
    $this->get('/create')->assertRedirect('/login');
});

it('lists active styles, the balance and the preview fee on the create page', function () {
    Style::factory()->create(['active' => false]);

    $this->actingAs($this->user)->get('/create')->assertInertia(fn (Assert $page) => $page
        ->component('Create')
        ->has('styles', 1)
        ->where('styles.0.id', $this->style->id)
        ->where('balance', 20)
        ->where('restyle_cost', 1));
});

it('has no way to skip the preview step', function () {
    $this->actingAs($this->user)->post('/creations', [
        'photo' => UploadedFile::fake()->image('me.jpg', 800, 800),
        'style_id' => $this->style->id,
    ])->assertStatus(405);

    expect(Creation::count())->toBe(0);
});

it('runs the whole two-stage flow end to end with the mock providers', function () {
    config([
        'queue.default' => 'sync',
        'stylizer.mock.fail_rate' => 0,
        'models.mock.delay_seconds' => 0,
        'models.mock.fail_rate' => 0,
    ]);

    // Stage 1: upload, restyle (sync queue runs the job immediately).
    $this->actingAs($this->user)->post('/stylizations', [
        'photo' => UploadedFile::fake()->image('me.jpg', 800, 800),
        'style_id' => $this->style->id,
    ])->assertRedirect();

    $stylization = Stylization::firstOrFail();
    expect($stylization->status)->toBe(StylizationStatus::Ready)
        ->and(app(CreditService::class)->balance($this->user))->toBe(19);
    Storage::disk('local')->assertExists($stylization->result_image_path);

    // Stage 2: approve, Meshy stand-in builds the model (sync queue).
    $this->actingAs($this->user)->post("/stylizations/{$stylization->id}/approve")->assertRedirect();

    $creation = Creation::firstOrFail();
    expect($creation->status)->toBe(CreationStatus::Succeeded)
        ->and($creation->cost_credits)->toBe(5)
        ->and(app(CreditService::class)->balance($this->user))->toBe(14)
        ->and($stylization->fresh()->status)->toBe(StylizationStatus::Approved);
    Storage::disk('local')->assertExists($creation->model_path);
});

it('refunds the preview fee when the restyle is refused, and the build fee when 3D fails', function () {
    config(['queue.default' => 'sync', 'stylizer.mock.fail_rate' => 1]);

    $this->actingAs($this->user)->post('/stylizations', [
        'photo' => UploadedFile::fake()->image('me.jpg', 800, 800),
        'style_id' => $this->style->id,
    ]);

    expect(Stylization::firstOrFail()->status)->toBe(StylizationStatus::Failed)
        ->and(app(CreditService::class)->balance($this->user))->toBe(20);

    config(['stylizer.mock.fail_rate' => 0, 'models.mock.delay_seconds' => 0, 'models.mock.fail_rate' => 1]);

    $this->actingAs($this->user)->post('/stylizations', [
        'photo' => UploadedFile::fake()->image('me.jpg', 800, 800),
        'style_id' => $this->style->id,
    ]);
    $ready = Stylization::where('status', StylizationStatus::Ready->value)->firstOrFail();
    $this->actingAs($this->user)->post("/stylizations/{$ready->id}/approve");

    expect(Creation::firstOrFail()->status)->toBe(CreationStatus::Failed)
        ->and(app(CreditService::class)->balance($this->user))->toBe(19); // only the preview fee stays spent
});

it('lists previews waiting for approval above the creations', function () {
    Storage::disk('local')->put('stylizations/i/original.jpg', fakeJpegBytes());
    Storage::disk('local')->put('stylizations/i/result.png', fakeJpegBytes());
    $ready = Stylization::factory()->create([
        'user_id' => $this->user->id,
        'style_id' => $this->style->id,
        'status' => StylizationStatus::Ready,
        'source_image_path' => 'stylizations/i/original.jpg',
        'result_image_path' => 'stylizations/i/result.png',
    ]);
    Stylization::factory()->create(['user_id' => $this->user->id, 'style_id' => $this->style->id, 'status' => StylizationStatus::Failed]);
    Stylization::factory()->create(['status' => StylizationStatus::Ready]); // someone else's

    $this->actingAs($this->user)->get('/creations')->assertInertia(fn (Assert $page) => $page
        ->component('creations/Index')
        ->has('previews', 1)
        ->where('previews.0.id', $ready->id)
        ->where('previews.0.urls.result', "/stylizations/{$ready->id}/files/result"));
});
