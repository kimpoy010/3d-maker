<?php

use App\Enums\LedgerReason;
use App\Enums\StylizationStatus;
use App\Jobs\RestylePhoto;
use App\Models\CreditLedgerEntry;
use App\Models\Style;
use App\Models\Stylization;
use App\Models\User;
use App\Services\Credits\CreditService;
use App\Services\ModelProviders\PermanentProviderException;
use App\Services\ModelProviders\TransientProviderException;
use App\Services\Stylizers\ImageStylizer;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config(['stylizer.mock.fail_rate' => 0, 'stylizer.job_deadline_seconds' => 300]);

    $this->credits = app(CreditService::class);
    $this->user = User::factory()->create();
    $this->credits->grant($this->user, 10, LedgerReason::Signup);

    $this->makeStylization = function (array $attrs = []) {
        $stylization = Stylization::factory()->create([
            'user_id' => $this->user->id,
            'style_id' => Style::factory(),
            'source_image_path' => 'stylizations/t/original.jpg',
        ] + $attrs);
        Storage::disk('local')->put('stylizations/t/original.jpg', fakeJpegBytes());
        $this->credits->spendForStylization($this->user, 1, $stylization);

        return $stylization;
    };

    $this->runJob = function (Stylization $stylization) {
        $job = (new RestylePhoto($stylization->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        return $job;
    };
});

it('stores the restyled image and keeps the charge', function () {
    $stylization = ($this->makeStylization)();

    ($this->runJob)($stylization);

    $fresh = $stylization->fresh();
    expect($fresh->status)->toBe(StylizationStatus::Ready)
        ->and($fresh->result_image_path)->toBe("stylizations/{$stylization->id}/result.png")
        ->and($this->credits->balance($this->user))->toBe(9);
    Storage::disk('local')->assertExists($fresh->result_image_path);
});

it('fails and refunds when the provider refuses the photo', function () {
    config(['stylizer.mock.fail_rate' => 1]);
    $stylization = ($this->makeStylization)();

    ($this->runJob)($stylization);
    ($this->runJob)($stylization); // a finished stylization is a no-op

    $fresh = $stylization->fresh();
    expect($fresh->status)->toBe(StylizationStatus::Failed)
        ->and($fresh->error)->toContain("can't process")
        ->and($this->credits->balance($this->user))->toBe(10)
        ->and(CreditLedgerEntry::where('reason', LedgerReason::StylizeRefund)->count())->toBe(1);
});

it('retries later on transient errors without failing', function () {
    $this->app->bind(ImageStylizer::class, fn () => new class implements ImageStylizer
    {
        public function stylize(string $photoPath, Style $style): string
        {
            throw new TransientProviderException('503');
        }
    });
    $stylization = ($this->makeStylization)();

    $job = ($this->runJob)($stylization);

    $job->assertReleased(delay: 10);
    expect($stylization->fresh()->status)->toBe(StylizationStatus::Processing)
        ->and($this->credits->balance($this->user))->toBe(9);
});

it('fails with the provider message on permanent errors', function () {
    $this->app->bind(ImageStylizer::class, fn () => new class implements ImageStylizer
    {
        public function stylize(string $photoPath, Style $style): string
        {
            throw new PermanentProviderException('Previews are temporarily unavailable. Please try again later.');
        }
    });
    $stylization = ($this->makeStylization)();

    ($this->runJob)($stylization);

    expect($stylization->fresh()->status)->toBe(StylizationStatus::Failed)
        ->and($stylization->fresh()->error)->toBe('Previews are temporarily unavailable. Please try again later.')
        ->and($this->credits->balance($this->user))->toBe(10);
});

it('times out, fails and refunds', function () {
    $stylization = ($this->makeStylization)();
    $stylization->forceFill(['created_at' => now()->subSeconds(301)])->save();

    ($this->runJob)($stylization);

    expect($stylization->fresh()->status)->toBe(StylizationStatus::Failed)
        ->and($stylization->fresh()->error)->toContain('timed out')
        ->and($this->credits->balance($this->user))->toBe(10);
});

it('does nothing for a preview that is already ready', function () {
    $stylization = ($this->makeStylization)(['status' => StylizationStatus::Ready, 'result_image_path' => 'stylizations/t/result.png']);

    ($this->runJob)($stylization);

    expect($stylization->fresh()->status)->toBe(StylizationStatus::Ready)
        ->and($this->credits->balance($this->user))->toBe(9);
});

it('deletes a result it stored if another run already finished the preview', function () {
    $this->app->bind(ImageStylizer::class, fn () => new class implements ImageStylizer
    {
        public function stylize(string $photoPath, Style $style): string
        {
            // Simulate the sweeper failing the preview while the provider call was running.
            Stylization::query()->update(['status' => StylizationStatus::Failed->value]);

            return 'png-bytes';
        }
    });
    $stylization = ($this->makeStylization)();

    ($this->runJob)($stylization);

    expect($stylization->fresh()->status)->toBe(StylizationStatus::Failed);
    Storage::disk('local')->assertMissing("stylizations/{$stylization->id}/result.png");
});

it('fails and refunds if the job itself blows up', function () {
    $stylization = ($this->makeStylization)();

    (new RestylePhoto($stylization->id))->failed(new RuntimeException('boom'));

    expect($stylization->fresh()->status)->toBe(StylizationStatus::Failed)
        ->and($this->credits->balance($this->user))->toBe(10);
});

it('allows one run per stylization at a time and keeps the timing invariant', function () {
    $job = new RestylePhoto(7);
    $lock = $job->middleware()[0];

    expect($lock)->toBeInstanceOf(WithoutOverlapping::class)
        ->and($lock->key)->toBe(7)
        ->and($lock->expiresAfter)->toBe(240)
        ->and((int) config('stylizer.openai.timeout_seconds'))->toBeLessThan($job->timeout)
        ->and($job->timeout)->toBeLessThan($lock->expiresAfter)
        ->and($lock->expiresAfter)->toBeLessThan((int) config('queue.connections.database.retry_after'));
});

it('keeps a result file that a winning run already stored', function () {
    $this->app->bind(ImageStylizer::class, fn () => new class implements ImageStylizer
    {
        public function stylize(string $photoPath, Style $style): string
        {
            // Simulate another run finishing first: it marks the row ready and stores the file.
            $id = Stylization::query()->value('id');
            Storage::disk('local')->put("stylizations/{$id}/result.png", 'winner');
            Stylization::query()->update([
                'status' => StylizationStatus::Ready->value,
                'result_image_path' => "stylizations/{$id}/result.png",
            ]);

            return 'loser';
        }
    });
    $stylization = ($this->makeStylization)();

    ($this->runJob)($stylization);

    expect($stylization->fresh()->status)->toBe(StylizationStatus::Ready);
    Storage::disk('local')->assertExists("stylizations/{$stylization->id}/result.png");
});

it('finishes a preview that is already processing, as after a transient release', function () {
    $stylization = ($this->makeStylization)(['status' => StylizationStatus::Processing]);

    ($this->runJob)($stylization);

    $fresh = $stylization->fresh();
    expect($fresh->status)->toBe(StylizationStatus::Ready)
        ->and($this->credits->balance($this->user))->toBe(9);
    Storage::disk('local')->assertExists($fresh->result_image_path);
});

it('stores a jpeg result under a .jpg name so the file route serves the right type', function () {
    app()->bind(ImageStylizer::class, fn () => new class implements ImageStylizer
    {
        public function stylize(string $photoPath, Style $style): string
        {
            return fakeJpegBytes(64, 96);
        }
    });
    $stylization = ($this->makeStylization)();

    ($this->runJob)($stylization);

    $fresh = $stylization->fresh();
    expect($fresh->status)->toBe(StylizationStatus::Ready)
        ->and($fresh->result_image_path)->toBe("stylizations/{$stylization->id}/result.jpg");
    Storage::disk('local')->assertExists($fresh->result_image_path);
});
