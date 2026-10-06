<?php

use App\Enums\ClickerStatus;
use App\Enums\LedgerReason;
use App\Exceptions\ClickerLimitReachedException;
use App\Exceptions\ClickerStateException;
use App\Exceptions\InsufficientCreditsException;
use App\Jobs\BuildClickerFiles;
use App\Models\Clicker;
use App\Models\ClickerBody;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Services\Clickers\ClickerBuilder;
use App\Services\Clickers\ClickerService;
use App\Services\Credits\CreditService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    config(['credits.clicker_cost' => 50, 'clicker.max_per_user' => 3]);

    $this->credits = app(CreditService::class);
    $this->service = app(ClickerService::class);
    $this->user = User::factory()->create();
    $this->credits->grant($this->user, 120, LedgerReason::Signup);
    $this->body = ClickerBody::factory()->create();
    $this->settings = Clicker::factory()->make()->settings;
    $this->make = fn () => $this->service->create($this->user, $this->body, 'Skull', $this->settings, fakePngBytes(), fakeJpegBytes(300, 300));
});

it('saves a draft with its artwork and thumbnail', function () {
    $clicker = ($this->make)();

    expect($clicker->status)->toBe(ClickerStatus::Draft)
        ->and($clicker->name)->toBe('Skull')
        ->and($clicker->settings['colors'])->toHaveCount(2)
        ->and($clicker->artwork_path)->toBe("clickers/{$clicker->id}/artwork.png")
        ->and($clicker->thumbnail_path)->toBe("clickers/{$clicker->id}/thumbnail.jpg");
    Storage::disk('local')->assertExists([$clicker->artwork_path, $clicker->thumbnail_path]);
});

it('stops at the saved design limit', function () {
    ($this->make)();
    ($this->make)();
    ($this->make)();

    expect(fn () => ($this->make)())->toThrow(ClickerLimitReachedException::class)
        ->and(Clicker::count())->toBe(3);
});

it('updates a draft and replaces the artwork only when new artwork is given', function () {
    $clicker = ($this->make)();
    $original = Storage::disk('local')->get($clicker->artwork_path);
    $other = ClickerBody::factory()->create();

    $updated = $this->service->update($clicker, $other, 'Renamed', $this->settings, null, null);
    expect($updated->name)->toBe('Renamed')
        ->and($updated->clicker_body_id)->toBe($other->id)
        ->and(Storage::disk('local')->get($updated->artwork_path))->toBe($original);

    $replacement = fakePngBytes(300, 300, false);
    $this->service->update($updated, $other, 'Renamed', $this->settings, $replacement, null);
    expect(Storage::disk('local')->get($clicker->fresh()->artwork_path))->toBe($replacement);
});

it('refuses to change a design once it has been unlocked', function () {
    $clicker = ($this->make)();
    $this->service->unlock($this->user, $clicker);

    expect(fn () => $this->service->update($clicker->fresh(), $this->body, 'x', $this->settings, null, null))
        ->toThrow(ClickerStateException::class, 'locked');
});

it('duplicates a design into a fresh draft with copies of its files', function () {
    $clicker = ($this->make)();
    $this->service->unlock($this->user, $clicker);

    $copy = $this->service->duplicate($this->user, $clicker->fresh());

    expect($copy->id)->not->toBe($clicker->id)
        ->and($copy->name)->toBe('Copy of Skull')
        ->and($copy->status)->toBe(ClickerStatus::Draft)
        ->and($copy->isUnlocked())->toBeFalse()
        ->and($copy->unlock_attempts)->toBe(0)
        ->and($copy->artwork_path)->toBe("clickers/{$copy->id}/artwork.png")
        ->and(Storage::disk('local')->get($copy->artwork_path))->toBe(Storage::disk('local')->get($clicker->artwork_path));
});

it('does not duplicate past the limit', function () {
    $clicker = ($this->make)();
    ($this->make)();
    ($this->make)();

    expect(fn () => $this->service->duplicate($this->user, $clicker))->toThrow(ClickerLimitReachedException::class);
});

it('charges once to unlock, starts a build, and ignores a second click', function () {
    $clicker = ($this->make)();

    $this->service->unlock($this->user, $clicker);
    $this->service->unlock($this->user, $clicker->fresh());

    $fresh = $clicker->fresh();
    expect($fresh->status)->toBe(ClickerStatus::Building)
        ->and($fresh->isUnlocked())->toBeTrue()
        ->and($fresh->unlock_attempts)->toBe(1)
        ->and($fresh->build_started_at)->not->toBeNull()
        ->and($this->credits->balance($this->user))->toBe(70)
        ->and(CreditLedgerEntry::where('reason', LedgerReason::ClickerDownload)->count())->toBe(1);
    Queue::assertPushed(BuildClickerFiles::class, 1);
});

it('keeps everything as it was when the balance is too low', function () {
    config(['credits.clicker_cost' => 500]);
    $clicker = ($this->make)();

    expect(fn () => $this->service->unlock($this->user, $clicker))->toThrow(InsufficientCreditsException::class);

    expect($clicker->fresh()->status)->toBe(ClickerStatus::Draft)
        ->and($clicker->fresh()->isUnlocked())->toBeFalse()
        ->and($clicker->fresh()->unlock_attempts)->toBe(0)
        ->and($this->credits->balance($this->user))->toBe(120);
    Queue::assertNothingPushed();
});

it('will not unlock a design without artwork', function () {
    $clicker = Clicker::factory()->create(['user_id' => $this->user->id]);

    expect(fn () => $this->service->unlock($this->user, $clicker))->toThrow(ClickerStateException::class, 'artwork');
    expect($this->credits->balance($this->user))->toBe(120);
});

it('marks a building design ready once, with its file paths', function () {
    $clicker = ($this->make)();
    $this->service->unlock($this->user, $clicker);

    expect($this->service->markReady($clicker->id, 'clickers/1/clicker.3mf', 'clickers/1/clicker-stl.zip'))->toBeTrue();

    $fresh = $clicker->fresh();
    expect($fresh->status)->toBe(ClickerStatus::Ready)
        ->and($fresh->threemf_path)->toBe('clickers/1/clicker.3mf')
        ->and($fresh->stl_zip_path)->toBe('clickers/1/clicker-stl.zip')
        ->and($this->service->markReady($clicker->id, 'a', 'b'))->toBeFalse();
});

it('fails a building design, refunds it exactly once and lets the user unlock again', function () {
    $clicker = ($this->make)();
    $this->service->unlock($this->user, $clicker);

    expect($this->service->markFailed($clicker->id, 'We could not build your files.'))->toBeTrue()
        ->and($this->service->markFailed($clicker->id, 'again'))->toBeFalse();

    $failed = $clicker->fresh();
    expect($failed->status)->toBe(ClickerStatus::Failed)
        ->and($failed->build_error)->toBe('We could not build your files.')
        ->and($failed->isUnlocked())->toBeFalse()
        ->and($this->credits->balance($this->user))->toBe(120);

    $this->service->unlock($this->user, $failed);

    $second = $clicker->fresh();
    expect($second->status)->toBe(ClickerStatus::Building)
        ->and($second->unlock_attempts)->toBe(2)
        ->and($second->build_error)->toBeNull()
        ->and($this->credits->balance($this->user))->toBe(70)
        ->and(CreditLedgerEntry::where('clicker_id', $clicker->id)->where('reason', LedgerReason::ClickerDownload)->pluck('clicker_attempt')->sort()->values()->all())->toBe([1, 2]);
    Queue::assertPushed(BuildClickerFiles::class, 2);
});

it('does not fail or refund a design that is already ready', function () {
    $clicker = ($this->make)();
    $this->service->unlock($this->user, $clicker);
    $this->service->markReady($clicker->id, 'a', 'b');

    expect($this->service->markFailed($clicker->id, 'late'))->toBeFalse()
        ->and($clicker->fresh()->status)->toBe(ClickerStatus::Ready)
        ->and($this->credits->balance($this->user))->toBe(70);
});

it('deletes a design with its files', function () {
    $clicker = ($this->make)();
    $path = $clicker->artwork_path;

    $this->service->destroy($clicker);

    expect(Clicker::count())->toBe(0);
    Storage::disk('local')->assertMissing($path);
});

it('refuses to delete a design that is being built, and still deletes a draft', function () {
    $building = ($this->make)();
    $this->service->unlock($this->user, $building);

    expect(fn () => $this->service->destroy($building->fresh()))->toThrow(ClickerStateException::class, 'being built');
    expect(Clicker::find($building->id))->not->toBeNull()
        ->and(CreditLedgerEntry::where('clicker_id', $building->id)->count())->toBe(1);
    Storage::disk('local')->assertExists($building->artwork_path);

    $draft = ($this->make)();
    $this->service->destroy($draft);
    expect(Clicker::find($draft->id))->toBeNull();
    Storage::disk('local')->assertMissing($draft->artwork_path);
});

it('does not overwrite artwork when the design was unlocked after the caller loaded it', function () {
    $clicker = ($this->make)();
    $stale = Clicker::find($clicker->id);
    $original = Storage::disk('local')->get($clicker->artwork_path);
    $this->service->unlock($this->user, $clicker);

    expect(fn () => $this->service->update($stale, $this->body, 'Sneaky', $this->settings, fakePngBytes(300, 300, false), null))
        ->toThrow(ClickerStateException::class);

    expect(Storage::disk('local')->get($clicker->artwork_path))->toBe($original)
        ->and($clicker->fresh()->name)->toBe('Skull');
});

it('ignores a stale attempt number when marking ready or failed', function () {
    $clicker = ($this->make)();
    $this->service->unlock($this->user, $clicker);
    $this->service->markFailed($clicker->id, 'first failed', 1);
    $this->service->unlock($this->user, $clicker->fresh());

    expect($this->service->markFailed($clicker->id, 'stale', 1))->toBeFalse()
        ->and($this->service->markReady($clicker->id, 'a', 'b', 1))->toBeFalse()
        ->and($clicker->fresh()->status)->toBe(ClickerStatus::Building)
        ->and($this->credits->balance($this->user))->toBe(70);

    expect($this->service->markReady($clicker->id, 'a', 'b', 2))->toBeTrue()
        ->and($clicker->fresh()->status)->toBe(ClickerStatus::Ready);

    $other = ($this->make)();
    $this->service->unlock($this->user, $other);
    expect($this->service->markFailed($other->id, 'current', 1))->toBeTrue()
        ->and($this->credits->balance($this->user))->toBe(70);
});

it('dispatches the build for the paid attempt and keys the overlap lock per attempt', function () {
    $clicker = ($this->make)();
    $this->service->unlock($this->user, $clicker);

    Queue::assertPushed(BuildClickerFiles::class, fn ($job) => $job->clickerId === $clicker->id && $job->attempt === 1);

    $one = new BuildClickerFiles(7, 1);
    $two = new BuildClickerFiles(7, 2);
    expect($one->middleware()[0]->key)->not->toBe($two->middleware()[0]->key)
        ->and((new BuildClickerFiles(7))->middleware()[0]->key)->toBe('7:0');
});

it('lets a stale attempt job neither build nor delete, while the current attempt and an unnumbered job build', function () {
    $fake = new class implements ClickerBuilder
    {
        public int $calls = 0;

        public function build(Clicker $clicker): array
        {
            $this->calls++;
            Storage::disk('local')->put("clickers/{$clicker->id}/clicker.3mf", 'x');
            Storage::disk('local')->put("clickers/{$clicker->id}/clicker-stl.zip", 'y');

            return ['threemf' => "clickers/{$clicker->id}/clicker.3mf", 'stl_zip' => "clickers/{$clicker->id}/clicker-stl.zip"];
        }
    };
    app()->instance(ClickerBuilder::class, $fake);

    $clicker = ($this->make)();
    $this->service->unlock($this->user, $clicker);
    $this->service->markFailed($clicker->id, 'first failed', 1);
    $this->service->unlock($this->user, $clicker->fresh());
    $disk = Storage::disk('local');
    $disk->put("clickers/{$clicker->id}/clicker.3mf", 'current');

    (new BuildClickerFiles($clicker->id, 1))->handle(app(ClickerBuilder::class), app(ClickerService::class));

    expect($fake->calls)->toBe(0)
        ->and($clicker->fresh()->status)->toBe(ClickerStatus::Building)
        ->and($clicker->fresh()->unlock_attempts)->toBe(2)
        ->and($disk->get("clickers/{$clicker->id}/clicker.3mf"))->toBe('current');

    (new BuildClickerFiles($clicker->id, 2))->handle(app(ClickerBuilder::class), app(ClickerService::class));

    expect($fake->calls)->toBe(1)
        ->and($clicker->fresh()->status)->toBe(ClickerStatus::Ready);

    $other = ($this->make)();
    $this->service->unlock($this->user, $other);
    (new BuildClickerFiles($other->id))->handle(app(ClickerBuilder::class), app(ClickerService::class));

    expect($fake->calls)->toBe(2)
        ->and($other->fresh()->status)->toBe(ClickerStatus::Ready);
});
