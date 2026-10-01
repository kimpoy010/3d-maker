<?php

use App\Enums\LedgerReason;
use App\Enums\StylizationStatus;
use App\Jobs\RestylePhoto;
use App\Models\Creation;
use App\Models\CreditLedgerEntry;
use App\Models\Style;
use App\Models\Stylization;
use App\Models\User;
use App\Services\Credits\CreditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    config(['credits.restyle_cost' => 1, 'stylizer.enabled' => true, 'stylizer.daily_limit' => 30]);

    $this->credits = app(CreditService::class);
    $this->user = User::factory()->create();
    $this->credits->grant($this->user, 20, LedgerReason::Signup);
    $this->other = User::factory()->create();
    $this->style = Style::factory()->create(['credit_cost' => 5]);

    $this->snapshot = fn (Stylization $s) => [
        'status' => $s->status->value,
        'creation_id' => $s->creation_id,
        'balance' => $this->credits->balance($this->user),
        'creations' => Creation::count(),
        'stylizations' => Stylization::count(),
        'files' => collect(Storage::disk('local')->allFiles())->sort()->values()->all(),
        'ledger' => CreditLedgerEntry::count(),
    ];

    $this->makeReady = function (array $attrs = []) {
        Storage::disk('local')->put('stylizations/h/original.jpg', fakeJpegBytes());
        Storage::disk('local')->put('stylizations/h/result.png', fakeJpegBytes(1024, 1536));
        $stylization = Stylization::factory()->create($attrs + [
            'user_id' => $this->user->id,
            'style_id' => $this->style->id,
            'status' => StylizationStatus::Ready,
            'source_image_path' => 'stylizations/h/original.jpg',
            'result_image_path' => 'stylizations/h/result.png',
        ]);
        $this->credits->spendForStylization($this->user, 1, $stylization);

        return $stylization;
    };
});

it('requires authentication', function () {
    $this->post('/stylizations')->assertRedirect('/login');
    $this->get('/stylizations/1')->assertRedirect('/login');
    $this->post('/stylizations/1/approve')->assertRedirect('/login');
    $this->post('/stylizations/1/retry')->assertRedirect('/login');
    $this->delete('/stylizations/1')->assertRedirect('/login');
    $this->get('/stylizations/1/files/original')->assertRedirect('/login');
    $this->get('/stylizations/1/files/result')->assertRedirect('/login');
});

it('snapshots a stylization so forbidden requests can prove they changed nothing', function () {
    $stylization = ($this->makeReady)();

    expect(($this->snapshot)($stylization))->toBe(($this->snapshot)($stylization->fresh()))
        ->and(($this->makeReady)(['status' => StylizationStatus::Failed])->status)->toBe(StylizationStatus::Failed);
});

describe('store', function () {
    it('creates a preview, charges the fee and queues the restyle', function () {
        $response = $this->actingAs($this->user)->post('/stylizations', [
            'photo' => UploadedFile::fake()->image('me.jpg', 800, 800),
            'style_id' => $this->style->id,
        ]);

        $stylization = Stylization::firstOrFail();
        $response->assertRedirect("/stylizations/{$stylization->id}");
        expect($stylization->status)->toBe(StylizationStatus::Queued)
            ->and($stylization->user_id)->toBe($this->user->id)
            ->and($this->credits->balance($this->user))->toBe(19);
        Storage::disk('local')->assertExists($stylization->source_image_path);
        Queue::assertPushed(RestylePhoto::class, fn ($job) => $job->stylizationId === $stylization->id);
    });

    it('validates the photo and the style', function (array $payload, string $field) {
        $this->actingAs($this->user)->post('/stylizations', $payload + [
            'photo' => UploadedFile::fake()->image('me.jpg', 800, 800),
            'style_id' => $this->style->id,
        ])->assertSessionHasErrors($field);

        expect(Stylization::count())->toBe(0);
    })->with([
        'not an image' => [['photo' => UploadedFile::fake()->create('x.txt', 10, 'text/plain')], 'photo'],
        'too small' => [['photo' => UploadedFile::fake()->image('s.jpg', 100, 100)], 'photo'],
        'too many pixels on one side' => [['photo' => UploadedFile::fake()->image('b.jpg', 8001, 600)], 'photo'],
        'missing style' => [['style_id' => null], 'style_id'],
        'unknown style' => [['style_id' => 9999], 'style_id'],
    ]);

    it('rejects inactive styles', function () {
        $inactive = Style::factory()->create(['active' => false]);

        $this->actingAs($this->user)->post('/stylizations', [
            'photo' => UploadedFile::fake()->image('me.jpg', 800, 800),
            'style_id' => $inactive->id,
        ])->assertSessionHasErrors('style_id');
    });

    it('rejects photos over 10 MB and stores, charges and creates nothing', function () {
        $this->actingAs($this->user)->post('/stylizations', [
            'photo' => UploadedFile::fake()->image('b.jpg', 800, 800)->size(11000),
            'style_id' => $this->style->id,
        ])->assertSessionHasErrors(['photo' => 'The photo must be 10 MB or smaller.']);

        expect(Stylization::count())->toBe(0)
            ->and(Storage::disk('local')->allFiles())->toBe([])
            ->and($this->credits->balance($this->user))->toBe(20);
    });

    it('rejects photos over 40 megapixels without decoding them', function () {
        // Header-only PNG claiming 8000x5001 (40.008 MP): enough for getimagesize, never decoded.
        $ihdr = pack('NN', 8000, 5001)."\x08\x02\x00\x00\x00";
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', $ihdr).$chunk('IEND', '');

        $this->actingAs($this->user)->post('/stylizations', [
            'photo' => UploadedFile::fake()->createWithContent('huge.png', $png),
            'style_id' => $this->style->id,
        ])->assertSessionHasErrors(['photo' => 'The photo is too large. Use an image under 40 megapixels.']);

        expect(Stylization::count())->toBe(0)
            ->and(Storage::disk('local')->allFiles())->toBe([])
            ->and($this->credits->balance($this->user))->toBe(20);
    });

    it('reports an unaffordable preview on the style field and creates nothing', function () {
        config(['credits.restyle_cost' => 50]);

        $this->actingAs($this->user)->post('/stylizations', [
            'photo' => UploadedFile::fake()->image('me.jpg', 800, 800),
            'style_id' => $this->style->id,
        ])->assertSessionHasErrors('style_id');

        expect(Stylization::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('explains the kill switch and the daily limit on the photo field', function () {
        config(['stylizer.enabled' => false]);
        $payload = fn () => ['photo' => UploadedFile::fake()->image('me.jpg', 800, 800), 'style_id' => $this->style->id];

        $this->actingAs($this->user)->post('/stylizations', $payload())
            ->assertSessionHasErrors(['photo' => 'Previews are temporarily unavailable. Please try again later.']);

        config(['stylizer.enabled' => true, 'stylizer.daily_limit' => 0]);
        $this->actingAs($this->user)->post('/stylizations', $payload())
            ->assertSessionHasErrors(['photo' => "You've reached today's preview limit. Try again tomorrow."]);
    });
});

describe('show', function () {
    it('shows a preview to its owner with file urls, the balance and the fee', function () {
        $stylization = ($this->makeReady)();

        $this->actingAs($this->user)->get("/stylizations/{$stylization->id}")->assertInertia(fn (Assert $page) => $page
            ->component('stylizations/Show')
            ->where('stylization.id', $stylization->id)
            ->where('stylization.status', 'ready')
            ->where('stylization.style.credit_cost', 5)
            ->where('stylization.urls.original', "/stylizations/{$stylization->id}/files/original")
            ->where('stylization.urls.result', "/stylizations/{$stylization->id}/files/result")
            ->where('balance', 19)
            ->where('restyle_cost', 1));
    });

    it('sends an approved preview on to its creation', function () {
        $creation = Creation::factory()->create(['user_id' => $this->user->id]);
        $stylization = ($this->makeReady)(['status' => StylizationStatus::Approved, 'creation_id' => $creation->id]);

        $this->actingAs($this->user)->get("/stylizations/{$stylization->id}")
            ->assertRedirect("/creations/{$creation->id}");
    });

    it('forbids other users', function () {
        $stylization = ($this->makeReady)();

        $this->actingAs($this->other)->get("/stylizations/{$stylization->id}")->assertForbidden();
    });
});

describe('files', function () {
    it('serves both images to the owner and 404s when a file is gone', function () {
        $stylization = ($this->makeReady)();

        $this->actingAs($this->user)->get("/stylizations/{$stylization->id}/files/original")->assertOk();
        $this->actingAs($this->user)->get("/stylizations/{$stylization->id}/files/result")->assertOk();

        $stylization->update(['result_image_path' => null]);
        $this->actingAs($this->user)->get("/stylizations/{$stylization->id}/files/result")->assertNotFound();
    });

    it('forbids other users and unknown types', function () {
        $stylization = ($this->makeReady)();

        $this->actingAs($this->other)->get("/stylizations/{$stylization->id}/files/original")->assertForbidden();
        $this->actingAs($this->other)->get("/stylizations/{$stylization->id}/files/result")->assertForbidden();
        $this->actingAs($this->user)->get("/stylizations/{$stylization->id}/files/model")->assertNotFound();
        $this->actingAs($this->user)->get("/stylizations/{$stylization->id}/files/..")->assertNotFound();
    });

    it('sends nosniff and a private cache header', function () {
        $stylization = ($this->makeReady)();

        foreach (['original', 'result'] as $type) {
            $this->actingAs($this->user)->get("/stylizations/{$stylization->id}/files/{$type}")
                ->assertOk()
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Cache-Control', 'max-age=3600, private');
        }
    });
});

describe('approve', function () {
    it('builds the 3D model and sends the customer to the creation page', function () {
        $stylization = ($this->makeReady)();

        $response = $this->actingAs($this->user)->post("/stylizations/{$stylization->id}/approve");

        $creation = Creation::firstOrFail();
        $response->assertRedirect("/creations/{$creation->id}");
        expect($stylization->fresh()->status)->toBe(StylizationStatus::Approved)
            ->and($this->credits->balance($this->user))->toBe(14);
    });

    it('is forbidden for other users', function () {
        $stylization = ($this->makeReady)();

        $before = ($this->snapshot)($stylization);

        $this->actingAs($this->other)->post("/stylizations/{$stylization->id}/approve")->assertForbidden();

        expect(Creation::count())->toBe(0)
            ->and(($this->snapshot)($stylization->fresh()))->toBe($before)
            ->and($stylization->fresh()->status)->toBe(StylizationStatus::Ready)
            ->and($this->credits->balance($this->user))->toBe(19);
    });

    it('keeps the preview and explains when the build is unaffordable', function () {
        $stylization = ($this->makeReady)();
        $this->style->update(['credit_cost' => 500]);

        $this->actingAs($this->user)->post("/stylizations/{$stylization->id}/approve")->assertSessionHasErrors('approve');

        expect($stylization->fresh()->status)->toBe(StylizationStatus::Ready)->and(Creation::count())->toBe(0);
    });

    it('explains when the preview cannot be approved', function () {
        $stylization = ($this->makeReady)(['status' => StylizationStatus::Failed]);

        $this->actingAs($this->user)->post("/stylizations/{$stylization->id}/approve")->assertSessionHasErrors('approve');
    });
});

describe('retry', function () {
    it('starts a new preview and sends the customer to it', function () {
        $stylization = ($this->makeReady)();

        $response = $this->actingAs($this->user)->post("/stylizations/{$stylization->id}/retry");

        $new = Stylization::where('id', '!=', $stylization->id)->firstOrFail();
        $response->assertRedirect("/stylizations/{$new->id}");
        expect($stylization->fresh()->status)->toBe(StylizationStatus::Discarded);
        Queue::assertPushed(RestylePhoto::class, fn ($job) => $job->stylizationId === $new->id);
    });

    it('is forbidden for other users and changes nothing', function () {
        $stylization = ($this->makeReady)();
        $before = ($this->snapshot)($stylization);

        $this->actingAs($this->other)->post("/stylizations/{$stylization->id}/retry")->assertForbidden();

        expect(($this->snapshot)($stylization->fresh()))->toBe($before)
            ->and($stylization->fresh()->status)->toBe(StylizationStatus::Ready)
            ->and($this->credits->balance($this->user))->toBe(19);
        Queue::assertNothingPushed();
    });

    it('explains an unaffordable retry', function () {
        $stylization = ($this->makeReady)();

        config(['credits.restyle_cost' => 500]);
        $this->actingAs($this->user)->post("/stylizations/{$stylization->id}/retry")->assertSessionHasErrors('retry');
    });
});

describe('destroy', function () {
    it('discards a ready preview and goes back to the list', function () {
        $stylization = ($this->makeReady)();

        $this->actingAs($this->user)->delete("/stylizations/{$stylization->id}")->assertRedirect('/creations');

        expect($stylization->fresh()->status)->toBe(StylizationStatus::Discarded);
        Storage::disk('local')->assertMissing('stylizations/h/original.jpg');
    });

    it('refuses to discard a preview that is still being made, and other users', function () {
        $working = ($this->makeReady)(['status' => StylizationStatus::Processing]);

        $this->actingAs($this->user)->delete("/stylizations/{$working->id}")->assertStatus(409);
        $this->actingAs($this->other)->delete("/stylizations/{$working->id}")->assertForbidden();
    });

    it('is forbidden for other users on a ready preview and changes nothing', function () {
        $stylization = ($this->makeReady)();
        $before = ($this->snapshot)($stylization);

        $this->actingAs($this->other)->delete("/stylizations/{$stylization->id}")->assertForbidden();

        expect(($this->snapshot)($stylization->fresh()))->toBe($before)
            ->and($stylization->fresh()->status)->toBe(StylizationStatus::Ready);
        Storage::disk('local')->assertExists('stylizations/h/original.jpg');
        Storage::disk('local')->assertExists('stylizations/h/result.png');
    });
});

it('throttles the generate routes at 10 requests a minute per user', function (string $path) {
    $this->actingAs($this->user);

    foreach (range(1, 10) as $i) {
        expect($this->post($path)->getStatusCode())->not->toBe(429);
    }

    $this->post($path)->assertStatus(429);
})->with([
    'store' => ['/stylizations'],
    'approve' => ['/stylizations/999999/approve'],
    'retry' => ['/stylizations/999999/retry'],
]);
