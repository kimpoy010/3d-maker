<?php

use App\Enums\CreationStatus;
use App\Enums\LedgerReason;
use App\Models\Creation;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Services\Credits\CreditService;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    $this->owner = User::factory()->create();
    $this->other = User::factory()->create();

    Storage::disk('local')->put('uploads/a.jpg', 'jpg-bytes');
    Storage::disk('local')->put('creations/1/model.glb', 'glb-bytes');

    $this->creation = Creation::factory()->create([
        'user_id' => $this->owner->id,
        'status' => CreationStatus::Succeeded,
        'source_image_path' => 'uploads/a.jpg',
        'model_path' => 'creations/1/model.glb',
        'thumbnail_path' => null,
        'downloads_unlocked_at' => now(),
    ]);
});

it('shows a creation to its owner with file urls', function () {
    $this->actingAs($this->owner)->get("/creations/{$this->creation->id}")->assertInertia(fn (Assert $page) => $page
        ->component('creations/Show')
        ->where('creation.id', $this->creation->id)
        ->where('creation.status', 'succeeded')
        ->where('creation.urls.model', "/creations/{$this->creation->id}/files/model")
        ->where('creation.urls.download', "/creations/{$this->creation->id}/files/model?download=1")
        ->where('creation.urls.thumbnail', null));
});

it('forbids other users from every creation route', function () {
    $id = $this->creation->id;

    $this->actingAs($this->other)->get("/creations/{$id}")->assertForbidden();
    $this->actingAs($this->other)->get("/creations/{$id}/files/source")->assertForbidden();
    $this->actingAs($this->other)->get("/creations/{$id}/files/model")->assertForbidden();
    $this->actingAs($this->other)->delete("/creations/{$id}")->assertForbidden();
});

it('serves files to the owner and supports download', function () {
    $id = $this->creation->id;

    $this->actingAs($this->owner)->get("/creations/{$id}/files/source")->assertOk();
    $this->actingAs($this->owner)->get("/creations/{$id}/files/model")->assertOk();
    $this->actingAs($this->owner)->get("/creations/{$id}/files/model?download=1")
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename=creation-'.$id.'-'.$this->creation->created_at->format('Ymd-His').'.glb');
    $this->actingAs($this->owner)->get("/creations/{$id}/files/thumbnail")->assertNotFound();
});

it('lists only the current users creations', function () {
    Creation::factory()->create(['user_id' => $this->other->id]);

    $this->actingAs($this->owner)->get('/creations')->assertInertia(fn (Assert $page) => $page
        ->component('creations/Index')
        ->has('creations', 1));
});

it('deletes a finished creation and its files', function () {
    $this->actingAs($this->owner)->delete("/creations/{$this->creation->id}")->assertRedirect('/creations');

    expect(Creation::count())->toBe(0);
    Storage::disk('local')->assertMissing('uploads/a.jpg');
    Storage::disk('local')->assertMissing('creations/1/model.glb');
});

it('refuses to delete a creation that is still running', function () {
    $running = Creation::factory()->create(['user_id' => $this->owner->id, 'status' => CreationStatus::Processing]);

    $this->actingAs($this->owner)->delete("/creations/{$running->id}")->assertForbidden();
    expect(Creation::count())->toBe(2);
});

it('serves the public demo model', function () {
    $this->get('/samples/demo.glb')->assertOk();
    expect(substr($this->get('/samples/demo.glb')->getContent(), 0, 4))->toBe('glTF');
});

it('serves creation files with safe, privacy-aware headers', function () {
    $id = $this->creation->id;
    Storage::disk('local')->put('creations/1/thumb.png', 'png-bytes');
    $this->creation->update(['thumbnail_path' => 'creations/1/thumb.png']);

    foreach (['source', 'thumbnail'] as $type) {
        $this->actingAs($this->owner)->get("/creations/{$id}/files/{$type}")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-cache, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', 'sandbox');
    }

    foreach (['model', 'model?download=1'] as $type) {
        $this->actingAs($this->owner)->get("/creations/{$id}/files/{$type}")
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', 'sandbox');
    }

    $cache = $this->actingAs($this->owner)->get("/creations/{$id}/files/model")->headers->get('Cache-Control');
    expect($cache)->toContain('max-age=3600')->toContain('private');
});

it('offers the STL download only when one was built, and only to the owner', function () {
    $id = $this->creation->id;

    $this->actingAs($this->owner)->get("/creations/{$id}")->assertInertia(fn (Assert $page) => $page
        ->where('creation.urls.download_stl', null));
    $this->actingAs($this->owner)->get("/creations/{$id}/files/print")->assertNotFound();

    Storage::disk('local')->put("creations/{$id}/print.stl", 'solid test');
    $this->creation->update(['print_model_path' => "creations/{$id}/print.stl"]);

    $this->actingAs($this->owner)->get("/creations/{$id}")->assertInertia(fn (Assert $page) => $page
        ->where('creation.urls.download_stl', "/creations/{$id}/files/print"));
    $this->actingAs($this->owner)->get("/creations/{$id}/files/print")
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename=creation-'.$id.'-'.$this->creation->created_at->format('Ymd-His').'.stl')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs($this->other)->get("/creations/{$id}/files/print")->assertForbidden();
});

describe('paid downloads', function () {
    beforeEach(function () {
        $this->creation->update(['downloads_unlocked_at' => null]);
        Storage::disk('local')->put('creations/1/print.stl', 'solid test');
        $this->creation->update(['print_model_path' => 'creations/1/print.stl']);
        config(['credits.download_cost' => 25]);
        $this->credits = app(CreditService::class);
    });

    it('hides the download links and refuses the files until the downloads are unlocked', function () {
        $id = $this->creation->id;

        $this->actingAs($this->owner)->get("/creations/{$id}")->assertInertia(fn (Assert $page) => $page
            ->where('creation.downloads_unlocked', false)
            ->where('creation.download_cost', 25)
            ->where('creation.urls.download', null)
            ->where('creation.urls.download_stl', null)
            ->where('creation.urls.model', "/creations/{$id}/files/model"));

        $this->actingAs($this->owner)->get("/creations/{$id}/files/model")->assertOk(); // viewing stays free
        $this->actingAs($this->owner)->get("/creations/{$id}/files/model?download=1")->assertForbidden();
        $this->actingAs($this->owner)->get("/creations/{$id}/files/print")->assertForbidden();
    });

    it('charges once to unlock and then serves both files', function () {
        $this->credits->grant($this->owner, 60, LedgerReason::Topup);
        $id = $this->creation->id;

        $this->actingAs($this->owner)->post("/creations/{$id}/unlock")->assertRedirect();
        $this->actingAs($this->owner)->post("/creations/{$id}/unlock")->assertRedirect(); // double click

        expect($this->credits->balance($this->owner))->toBe(35)
            ->and($this->creation->fresh()->downloads_unlocked_at)->not->toBeNull()
            ->and(CreditLedgerEntry::where('reason', LedgerReason::Download)->count())->toBe(1);
        $this->actingAs($this->owner)->get("/creations/{$id}/files/model?download=1")->assertOk();
        $this->actingAs($this->owner)->get("/creations/{$id}/files/print")->assertOk();
    });

    it('explains an unaffordable unlock and changes nothing', function () {
        $this->credits->grant($this->owner, 10, LedgerReason::Topup);
        $id = $this->creation->id;

        $this->actingAs($this->owner)->post("/creations/{$id}/unlock")
            ->assertSessionHasErrors(['unlock' => 'Not enough balance: unlocking downloads costs ₱25 and you have ₱10.']);

        expect($this->credits->balance($this->owner))->toBe(10)
            ->and($this->creation->fresh()->downloads_unlocked_at)->toBeNull();
    });

    it('only lets the owner unlock, and only a finished model', function () {
        $this->credits->grant($this->owner, 60, LedgerReason::Topup);
        $this->credits->grant($this->other, 60, LedgerReason::Topup);
        $id = $this->creation->id;

        $this->actingAs($this->other)->post("/creations/{$id}/unlock")->assertForbidden();

        $this->creation->update(['status' => CreationStatus::Processing, 'model_path' => null]);
        $this->actingAs($this->owner)->post("/creations/{$id}/unlock")->assertSessionHasErrors('unlock');

        expect($this->credits->balance($this->owner))->toBe(60)->and($this->credits->balance($this->other))->toBe(60);
    });
});

it('gives the home page a versioned sample url so a cached older sample is replaced', function () {
    $version = filemtime(resource_path('samples/demo.glb'));

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->component('Welcome')
        ->where('sampleUrl', "/samples/demo.glb?v={$version}"));
});
