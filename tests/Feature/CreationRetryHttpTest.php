<?php

use App\Enums\CreationStatus;
use App\Enums\LedgerReason;
use App\Jobs\GenerateCreation;
use App\Models\Creation;
use App\Models\Style;
use App\Models\User;
use App\Services\Credits\CreditService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->credits = app(CreditService::class);
    $this->user = User::factory()->create();
    $this->credits->grant($this->user, 20, LedgerReason::Signup);
    $this->other = User::factory()->create();
    $this->credits->grant($this->other, 20, LedgerReason::Signup);
    $this->style = Style::factory()->create(['credit_cost' => 5]);

    $this->makeCreation = function (CreationStatus $status) {
        Storage::disk('local')->put('uploads/c.jpg', fakeJpegBytes());

        return Creation::factory()->create([
            'user_id' => $this->user->id,
            'style_id' => $this->style->id,
            'status' => $status,
            'source_image_path' => 'uploads/c.jpg',
        ]);
    };
});

it('requires authentication', function () {
    $this->post('/creations/1/retry')->assertRedirect('/login');
});

it('retries a failed creation and sends the customer to the new one', function () {
    $failed = ($this->makeCreation)(CreationStatus::Failed);

    $response = $this->actingAs($this->user)->post("/creations/{$failed->id}/retry");

    $new = Creation::where('id', '!=', $failed->id)->firstOrFail();
    $response->assertRedirect("/creations/{$new->id}");
    expect($this->credits->balance($this->user))->toBe(15);
    Queue::assertPushed(GenerateCreation::class, fn ($job) => $job->creationId === $new->id);
});

it('is forbidden for other users and changes nothing', function () {
    $failed = ($this->makeCreation)(CreationStatus::Failed);
    $files = Storage::disk('local')->allFiles();

    $this->actingAs($this->other)->post("/creations/{$failed->id}/retry")->assertForbidden();

    expect(Creation::count())->toBe(1)
        ->and($this->credits->balance($this->other))->toBe(20)
        ->and($this->credits->balance($this->user))->toBe(20)
        ->and(Storage::disk('local')->allFiles())->toBe($files);
    Queue::assertNothingPushed();
});

it('is forbidden for creations that did not fail and changes nothing', function () {
    $succeeded = ($this->makeCreation)(CreationStatus::Succeeded);
    $files = Storage::disk('local')->allFiles();

    $this->actingAs($this->user)->post("/creations/{$succeeded->id}/retry")->assertForbidden();

    expect(Creation::count())->toBe(1)
        ->and($this->credits->balance($this->user))->toBe(20)
        ->and(Storage::disk('local')->allFiles())->toBe($files);
    Queue::assertNothingPushed();
});

it('explains an unaffordable retry and changes nothing', function () {
    $failed = ($this->makeCreation)(CreationStatus::Failed);
    $this->style->update(['credit_cost' => 500]);
    $files = Storage::disk('local')->allFiles();

    $this->actingAs($this->user)->post("/creations/{$failed->id}/retry")->assertSessionHasErrors('retry');

    expect(Creation::count())->toBe(1)
        ->and($this->credits->balance($this->user))->toBe(20)
        ->and(Storage::disk('local')->allFiles())->toBe($files);
    Queue::assertNothingPushed();
});
