<?php

use App\Enums\ClickerStatus;
use App\Enums\LedgerReason;
use App\Exceptions\ClickerStateException;
use App\Jobs\BuildClickerFiles;
use App\Models\Clicker;
use App\Models\ClickerBody;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Services\Clickers\ClickerService;
use App\Services\Credits\CreditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    config(['credits.clicker_cost' => 50, 'clicker.max_per_user' => 3]);

    $this->credits = app(CreditService::class);
    $this->user = User::factory()->create();
    $this->other = User::factory()->create();
    $this->credits->grant($this->user, 120, LedgerReason::Signup);
    $this->body = ClickerBody::factory()->create();
    $this->settings = Clicker::factory()->make(['clicker_body_id' => $this->body->id])->settings;

    $this->payload = fn (array $over = []) => array_merge([
        'name' => 'Skull',
        'clicker_body_id' => $this->body->id,
        'settings' => $this->settings,
        'artwork' => UploadedFile::fake()->createWithContent('art.png', fakePngBytes()),
    ], $over);

    $this->saved = function (array $attrs = []): Clicker {
        $clicker = Clicker::factory()->create(['user_id' => $this->user->id, 'clicker_body_id' => $this->body->id] + $attrs);
        $clicker->update(['artwork_path' => "clickers/{$clicker->id}/artwork.png"]);
        Storage::disk('local')->put($clicker->artwork_path, fakePngBytes());

        return $clicker->fresh();
    };
});

it('sends guests to the login page', function () {
    $this->get('/clickers')->assertRedirect('/login');
    $this->get('/clickers/create')->assertRedirect('/login');
    $this->post('/clickers')->assertRedirect('/login');
});

it('lists only the current users designs', function () {
    ($this->saved)();
    Clicker::factory()->create(['user_id' => $this->other->id]);

    $this->actingAs($this->user)->get('/clickers')->assertInertia(fn (Assert $page) => $page
        ->component('clickers/Index')
        ->has('clickers', 1)
        ->where('limit', 3));
});

it('shows the editor with the active bodies but never their model files', function () {
    ClickerBody::factory()->create(['active' => false]);

    $this->actingAs($this->user)->get('/clickers/create')->assertInertia(fn (Assert $page) => $page
        ->component('clickers/Edit')
        ->where('clicker', null)
        ->has('bodies', 1)
        ->where('bodies.0.config.cap.shape', 'rounded')
        ->missing('bodies.0.model_files')
        ->missing('bodies.0.config.base_thickness')
        ->where('balance', 120)
        ->where('unlock_cost', 50)
        ->where('max_colors', 8));
});

it('still passes a design its own body after the body is retired', function () {
    $clicker = ($this->saved)();
    $other = ClickerBody::factory()->create(['active' => false]);
    $this->body->update(['active' => false]);

    $this->actingAs($this->user)->get("/clickers/{$clicker->id}")->assertInertia(fn (Assert $page) => $page
        ->has('bodies', 1)
        ->where('bodies.0.id', $this->body->id));

    expect($other->active)->toBeFalse();
});

it('shows the duplicate limit error on the name field', function () {
    $clicker = ($this->saved)();
    ($this->saved)();
    ($this->saved)();

    $this->actingAs($this->user)->post("/clickers/{$clicker->id}/duplicate")
        ->assertSessionHasErrors(['name' => 'You have reached the limit of 3 saved clickers. Delete one to make room.']);
    expect(Clicker::count())->toBe(3);
});

it('saves a new design with typed settings and its files', function () {
    $this->actingAs($this->user)->post('/clickers', ($this->payload)([
        'settings' => array_replace($this->settings, ['scale' => '0.5', 'colors' => [
            ['hex' => '#1d4ed8', 'height_mm' => '1.5', 'enabled' => '1'],
            ['hex' => '#DC2626', 'height_mm' => '2', 'enabled' => '0'],
        ]]),
        'thumbnail' => UploadedFile::fake()->createWithContent('thumb.png', fakePngBytes(300, 300, false)),
    ]))->assertRedirect();

    $clicker = Clicker::firstOrFail();
    expect($clicker->user_id)->toBe($this->user->id)
        ->and($clicker->name)->toBe('Skull')
        ->and($clicker->status)->toBe(ClickerStatus::Draft)
        ->and($clicker->settings['scale'])->toBe(0.5)
        ->and($clicker->settings['colors'][0])->toBe(['hex' => '#1D4ED8', 'height_mm' => 1.5, 'enabled' => true])
        ->and($clicker->settings['colors'][1]['enabled'])->toBeFalse();
    Storage::disk('local')->assertExists([$clicker->artwork_path, $clicker->thumbnail_path]);
});

it('stores each valid base shape and defaults a missing one to square', function (?string $shape, string $expected) {
    $settings = $this->settings;
    unset($settings['base_shape']);
    if ($shape !== null) {
        $settings['base_shape'] = $shape;
    }

    $this->actingAs($this->user)->post('/clickers', ($this->payload)(['settings' => $settings]))->assertSessionHasNoErrors();

    expect(Clicker::firstOrFail()->settings['base_shape'])->toBe($expected);
})->with([
    'square' => ['square', 'square'],
    'circle' => ['circle', 'circle'],
    'trace' => ['trace', 'trace'],
    'missing' => [null, 'square'],
]);

it('rejects an unknown base shape', function () {
    $this->actingAs($this->user)
        ->post('/clickers', ($this->payload)(['settings' => array_replace($this->settings, ['base_shape' => 'hexagon'])]))
        ->assertSessionHasErrors('settings.base_shape');

    expect(Clicker::count())->toBe(0);
});

it('stores the hollow cap and background settings as real booleans', function (mixed $hollow, mixed $remove, bool $expectedHollow, bool $expectedRemove) {
    $settings = array_replace($this->settings, ['hollow_cap' => $hollow, 'remove_background' => $remove]);

    $this->actingAs($this->user)->post('/clickers', ($this->payload)(['settings' => $settings]))->assertSessionHasNoErrors();

    $stored = Clicker::firstOrFail()->settings;
    expect($stored['hollow_cap'])->toBe($expectedHollow)
        ->and($stored['remove_background'])->toBe($expectedRemove);
})->with([
    'true true' => [true, true, true, true],
    'true false' => [true, false, true, false],
    'false true' => [false, true, false, true],
    'false false' => [false, false, false, false],
    'strings' => ['1', '0', true, false],
]);

it('defaults a missing background setting by body type and a missing hollow cap to off', function (bool $keycap, bool $expected) {
    $body = $keycap ? ClickerBody::factory()->keycap()->create() : $this->body;
    $settings = $this->settings;
    unset($settings['hollow_cap'], $settings['remove_background']);

    $this->actingAs($this->user)
        ->post('/clickers', ($this->payload)(['clicker_body_id' => $body->id, 'settings' => $settings]))
        ->assertSessionHasNoErrors();

    $stored = Clicker::firstOrFail()->settings;
    expect($stored['hollow_cap'])->toBeFalse()
        ->and($stored['remove_background'])->toBe($expected);
})->with([
    'keycap body' => [true, true],
    'legacy body' => [false, false],
]);

it('keeps an explicit background setting on either body type', function (bool $keycap, bool $value) {
    $body = $keycap ? ClickerBody::factory()->keycap()->create() : $this->body;

    $this->actingAs($this->user)
        ->post('/clickers', ($this->payload)(['clicker_body_id' => $body->id, 'settings' => array_replace($this->settings, ['remove_background' => $value])]))
        ->assertSessionHasNoErrors();

    expect(Clicker::firstOrFail()->settings['remove_background'])->toBe($value);
})->with([
    'keycap on' => [true, true],
    'keycap off' => [true, false],
    'legacy on' => [false, true],
    'legacy off' => [false, false],
]);

it('rejects a non-boolean hollow cap or background setting', function (string $field) {
    $this->actingAs($this->user)
        ->post('/clickers', ($this->payload)(['settings' => array_replace($this->settings, [$field => 'maybe'])]))
        ->assertSessionHasErrors("settings.{$field}");

    expect(Clicker::count())->toBe(0);
})->with(['hollow_cap', 'remove_background']);

it('stores the cap insertion as a float', function (mixed $value, float $expected) {
    $this->actingAs($this->user)
        ->post('/clickers', ($this->payload)(['settings' => array_replace($this->settings, ['cap_insertion' => $value])]))
        ->assertSessionHasNoErrors();

    // JSON storage turns 1.0 back into 1, so compare by value.
    expect(Clicker::firstOrFail()->settings['cap_insertion'])->toEqual($expected);
})->with([
    'half' => [0.5, 0.5],
    'three quarters' => [0.75, 0.75],
    'full int' => [1, 1.0],
    'string' => ['0.85', 0.85],
]);

it('defaults a missing cap insertion to half', function () {
    $settings = $this->settings;
    unset($settings['cap_insertion']);

    $this->actingAs($this->user)->post('/clickers', ($this->payload)(['settings' => $settings]))->assertSessionHasNoErrors();

    expect(Clicker::firstOrFail()->settings['cap_insertion'])->toBe(0.5);
});

it('rejects a cap insertion outside 0.5 to 1 or not a number', function (mixed $value) {
    $this->actingAs($this->user)
        ->post('/clickers', ($this->payload)(['settings' => array_replace($this->settings, ['cap_insertion' => $value])]))
        ->assertSessionHasErrors('settings.cap_insertion');

    expect(Clicker::count())->toBe(0);
})->with([0.4, 1.1, 'abc']);

it('loads an old design without a cap insertion as half', function () {
    $settings = $this->settings;
    unset($settings['cap_insertion']);
    $clicker = ($this->saved)(['settings' => $settings]);

    $this->actingAs($this->user)->get("/clickers/{$clicker->id}")->assertInertia(fn (Assert $page) => $page
        ->where('clicker.settings.cap_insertion', 0.5));
});

it('gives an old design without the new settings their defaults in the editor', function () {
    $clicker = ($this->saved)();

    $this->actingAs($this->user)->get("/clickers/{$clicker->id}")->assertInertia(fn (Assert $page) => $page
        ->where('clicker.settings.hollow_cap', false)
        ->missing('clicker.settings.remove_background')
        ->where('clicker.settings.scale', $clicker->settings['scale']));
});

it('keeps saved hollow cap and background values in the editor', function () {
    $clicker = ($this->saved)(['settings' => array_replace($this->settings, ['hollow_cap' => true, 'remove_background' => false])]);

    $this->actingAs($this->user)->get("/clickers/{$clicker->id}")->assertInertia(fn (Assert $page) => $page
        ->where('clicker.settings.hollow_cap', true)
        ->where('clicker.settings.remove_background', false));
});

it('updates the hollow cap setting of a saved design', function () {
    $clicker = ($this->saved)();

    $this->actingAs($this->user)->put("/clickers/{$clicker->id}", [
        'name' => 'Skull',
        'clicker_body_id' => $this->body->id,
        'settings' => array_replace($this->settings, ['hollow_cap' => true]),
    ])->assertSessionHasNoErrors();

    expect($clicker->fresh()->settings['hollow_cap'])->toBeTrue();
});

it('hands the generator and keycap config of a body to the editor', function () {
    ClickerBody::query()->delete();
    ClickerBody::factory()->keycap()->create();

    $this->actingAs($this->user)->get('/clickers/create')->assertInertia(fn (Assert $page) => $page
        ->where('bodies.0.config.generator', 'mx_keycap')
        ->where('bodies.0.config.keycap.size', 30)
        ->missing('bodies.0.model_files'));
});

it('validates the form and creates nothing', function (string $field, array $override) {
    $this->actingAs($this->user)->post('/clickers', ($this->payload)($override))->assertSessionHasErrors($field);

    expect(Clicker::count())->toBe(0);
})->with([
    'no name' => ['name', ['name' => '']],
    'unknown body' => ['clicker_body_id', ['clicker_body_id' => 9999]],
    'bad hex' => ['settings.colors.0.hex', ['settings' => ['colors' => [['hex' => 'red', 'height_mm' => 1, 'enabled' => true]]] + ['scale' => 1, 'offset_x' => 0, 'offset_y' => 0, 'outline_mm' => 0, 'outline_hex' => '#111111']]],
    'no colours' => ['settings.colors', ['settings' => ['colors' => []] + ['scale' => 1, 'offset_x' => 0, 'offset_y' => 0, 'outline_mm' => 0, 'outline_hex' => '#111111']]],
    'no artwork' => ['artwork', ['artwork' => null]],
    'artwork is a jpeg' => ['artwork', ['artwork' => UploadedFile::fake()->image('art.jpg', 400, 400)]],
]);

it('rejects too many colours, absurd heights and out-of-range scale', function () {
    $colors = array_fill(0, 9, ['hex' => '#FFFFFF', 'height_mm' => 1, 'enabled' => true]);
    $base = ['scale' => 1, 'offset_x' => 0, 'offset_y' => 0, 'outline_mm' => 0, 'outline_hex' => '#111111'];

    $this->actingAs($this->user)->post('/clickers', ($this->payload)(['settings' => ['colors' => $colors] + $base]))->assertSessionHasErrors('settings.colors');
    $this->actingAs($this->user)->post('/clickers', ($this->payload)(['settings' => ['colors' => [['hex' => '#FFFFFF', 'height_mm' => 99, 'enabled' => true]]] + $base]))->assertSessionHasErrors('settings.colors.0.height_mm');
    $this->actingAs($this->user)->post('/clickers', ($this->payload)(['settings' => ['colors' => $this->settings['colors'], 'scale' => 5] + $base]))->assertSessionHasErrors('settings.scale');
});

it('rejects a PNG that cannot be decoded', function () {
    $this->actingAs($this->user)->post('/clickers', ($this->payload)([
        'artwork' => UploadedFile::fake()->createWithContent('art.png', truncatedPngBytes()),
    ]))->assertSessionHasErrors('artwork');

    expect(Clicker::count())->toBe(0);
});

it('stops at the saved design limit with a clear message', function () {
    foreach (range(1, 3) as $_) {
        ($this->saved)();
    }

    $this->actingAs($this->user)->post('/clickers', ($this->payload)())->assertSessionHasErrors(['name' => 'You have reached the limit of 3 saved clickers. Delete one to make room.']);
});

it('shows a design to its owner only', function () {
    $clicker = ($this->saved)();

    $this->actingAs($this->user)->get("/clickers/{$clicker->id}")->assertInertia(fn (Assert $page) => $page
        ->component('clickers/Edit')
        ->where('clicker.id', $clicker->id)
        ->where('clicker.editable', true)
        ->where('clicker.unlocked', false)
        ->where('clicker.urls.artwork', "/clickers/{$clicker->id}/files/artwork")
        ->where('clicker.urls.threemf', null)
        ->where('clicker.urls.stl_zip', null));

    $this->actingAs($this->other)->get("/clickers/{$clicker->id}")->assertForbidden();
});

it('updates a draft and refuses to change a locked design', function () {
    $clicker = ($this->saved)();

    $this->actingAs($this->user)->put("/clickers/{$clicker->id}", ($this->payload)(['name' => 'Renamed', 'artwork' => null]))->assertRedirect();
    expect($clicker->fresh()->name)->toBe('Renamed');

    $this->actingAs($this->other)->put("/clickers/{$clicker->id}", ($this->payload)(['artwork' => null]))->assertForbidden();

    $clicker->update(['downloads_unlocked_at' => now()]);
    $this->actingAs($this->user)->put("/clickers/{$clicker->id}", ($this->payload)(['name' => 'Nope', 'artwork' => null]))->assertForbidden();
    expect($clicker->fresh()->name)->toBe('Renamed');
});

it('duplicates a design for its owner', function () {
    $clicker = ($this->saved)();

    $this->actingAs($this->user)->post("/clickers/{$clicker->id}/duplicate")->assertRedirect();
    expect(Clicker::count())->toBe(2)->and(Clicker::latest('id')->first()->name)->toBe('Copy of My clicker');

    $this->actingAs($this->other)->post("/clickers/{$clicker->id}/duplicate")->assertForbidden();
});

it('deletes a design, but not while it is building or for someone else', function () {
    $clicker = ($this->saved)();

    $this->actingAs($this->other)->delete("/clickers/{$clicker->id}")->assertForbidden();

    $clicker->update(['status' => ClickerStatus::Building]);
    $this->actingAs($this->user)->delete("/clickers/{$clicker->id}")->assertForbidden();

    $clicker->update(['status' => ClickerStatus::Draft]);
    $this->actingAs($this->user)->delete("/clickers/{$clicker->id}")->assertRedirect('/clickers');
    expect(Clicker::count())->toBe(0);
    Storage::disk('local')->assertMissing("clickers/{$clicker->id}/artwork.png");
});

it('unlocks once, starts the build and charges a single time', function () {
    $clicker = ($this->saved)();

    $this->actingAs($this->user)->post("/clickers/{$clicker->id}/unlock")->assertRedirect();
    $this->actingAs($this->user)->post("/clickers/{$clicker->id}/unlock")->assertForbidden(); // already unlocked

    expect($clicker->fresh()->status)->toBe(ClickerStatus::Building)
        ->and($this->credits->balance($this->user))->toBe(70)
        ->and(CreditLedgerEntry::where('reason', LedgerReason::ClickerDownload)->count())->toBe(1);
    Queue::assertPushed(BuildClickerFiles::class, 1);
});

it('explains an unaffordable unlock and changes nothing', function () {
    $clicker = ($this->saved)();
    config(['credits.clicker_cost' => 500]);

    $this->actingAs($this->user)->post("/clickers/{$clicker->id}/unlock")
        ->assertSessionHasErrors(['unlock' => 'Not enough balance: unlocking downloads costs ₱500 and you have ₱120.']);

    expect($clicker->fresh()->status)->toBe(ClickerStatus::Draft)->and($this->credits->balance($this->user))->toBe(120);
    Queue::assertNothingPushed();
});

it('only lets the owner unlock', function () {
    $clicker = ($this->saved)();

    $this->actingAs($this->other)->post("/clickers/{$clicker->id}/unlock")->assertForbidden();
    expect($this->credits->balance($this->user))->toBe(120);
});

it('lets a failed design be unlocked again', function () {
    $clicker = ($this->saved)(['status' => ClickerStatus::Failed, 'unlock_attempts' => 1, 'build_error' => 'We could not build your files.']);
    $this->credits->spendForClicker($this->user, 50, $clicker, 1);
    $this->credits->refundClicker($clicker, 1);

    $this->actingAs($this->user)->post("/clickers/{$clicker->id}/unlock")->assertRedirect();

    expect($clicker->fresh()->unlock_attempts)->toBe(2)->and($this->credits->balance($this->user))->toBe(70);
});

it('turns a delete that loses the race against an unlock into a message, not a 500', function () {
    $clicker = ($this->saved)();

    $this->mock(ClickerService::class, fn ($mock) => $mock
        ->shouldReceive('destroy')->once()
        ->andThrow(new ClickerStateException('This design is being built and cannot be deleted yet.')));

    $this->actingAs($this->user)->delete("/clickers/{$clicker->id}")
        ->assertSessionHasErrors(['name' => 'This design is being built and cannot be deleted yet.']);

    expect(Clicker::find($clicker->id))->not->toBeNull();
});
