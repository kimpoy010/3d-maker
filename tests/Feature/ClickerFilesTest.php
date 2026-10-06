<?php

use App\Enums\ClickerStatus;
use App\Models\Clicker;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->owner = User::factory()->create();
    $this->other = User::factory()->create();
    $this->clicker = Clicker::factory()->create(['user_id' => $this->owner->id]);
    $dir = $this->clicker->directory();

    Storage::disk('local')->put("{$dir}/artwork.png", fakePngBytes());
    Storage::disk('local')->put("{$dir}/thumbnail.jpg", fakeJpegBytes(100, 100));
    Storage::disk('local')->put("{$dir}/clicker.3mf", 'three-mf');
    Storage::disk('local')->put("{$dir}/clicker-stl.zip", 'stl-zip');
    $this->clicker->update([
        'artwork_path' => "{$dir}/artwork.png",
        'thumbnail_path' => "{$dir}/thumbnail.jpg",
        'threemf_path' => "{$dir}/clicker.3mf",
        'stl_zip_path' => "{$dir}/clicker-stl.zip",
    ]);
});

it('serves the artwork and thumbnail to the owner with safe headers', function () {
    foreach (['artwork', 'thumbnail'] as $type) {
        $this->actingAs($this->owner)->get("/clickers/{$this->clicker->id}/files/{$type}")
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', 'sandbox')
            ->assertHeader('Cache-Control', 'no-cache, private');
    }
});

it('forbids everything to other users', function () {
    $this->clicker->update(['downloads_unlocked_at' => now(), 'status' => ClickerStatus::Ready]);

    foreach (['artwork', 'thumbnail', '3mf', 'stl'] as $type) {
        $this->actingAs($this->other)->get("/clickers/{$this->clicker->id}/files/{$type}")->assertForbidden();
    }
});

it('keeps the print files back until the design is unlocked and ready', function () {
    foreach (['3mf', 'stl'] as $type) {
        $this->actingAs($this->owner)->get("/clickers/{$this->clicker->id}/files/{$type}")->assertForbidden();
    }

    $this->clicker->update(['downloads_unlocked_at' => now(), 'status' => ClickerStatus::Building]);
    foreach (['3mf', 'stl'] as $type) {
        $this->actingAs($this->owner)->get("/clickers/{$this->clicker->id}/files/{$type}")->assertForbidden();
    }
});

it('serves the print files as named downloads once ready', function () {
    $this->clicker->update(['downloads_unlocked_at' => now(), 'status' => ClickerStatus::Ready]);
    $stamp = $this->clicker->created_at->format('Ymd-His');

    $this->actingAs($this->owner)->get("/clickers/{$this->clicker->id}/files/3mf")
        ->assertOk()
        ->assertHeader('content-disposition', "attachment; filename=clicker-{$this->clicker->id}-{$stamp}.3mf")
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs($this->owner)->get("/clickers/{$this->clicker->id}/files/stl")
        ->assertOk()
        ->assertHeader('content-disposition', "attachment; filename=clicker-{$this->clicker->id}-{$stamp}-stl.zip");
});

it('answers 404 for a missing file and for an unknown type', function () {
    $this->clicker->update(['thumbnail_path' => null]);

    $this->actingAs($this->owner)->get("/clickers/{$this->clicker->id}/files/thumbnail")->assertNotFound();
    $this->actingAs($this->owner)->get("/clickers/{$this->clicker->id}/files/secret")->assertNotFound();
});

it('exposes the download links only for an unlocked, ready design', function () {
    $this->clicker->update(['downloads_unlocked_at' => now(), 'status' => ClickerStatus::Ready]);

    $this->actingAs($this->owner)->get("/clickers/{$this->clicker->id}")->assertInertia(fn ($page) => $page
        ->where('clicker.urls.threemf', "/clickers/{$this->clicker->id}/files/3mf")
        ->where('clicker.urls.stl_zip', "/clickers/{$this->clicker->id}/files/stl")
        ->where('clicker.editable', false));
});
