<?php

use App\Enums\CreationStatus;
use App\Models\Creation;
use App\Models\User;
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
        ->assertHeader('content-disposition', 'attachment; filename=creation-'.$id.'.glb');
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
        ->assertHeader('content-disposition', 'attachment; filename=creation-'.$id.'.stl')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs($this->other)->get("/creations/{$id}/files/print")->assertForbidden();
});
