<?php

use App\Models\Clicker;
use App\Models\ClickerBody;
use App\Services\Clickers\ClickerBuildException;
use App\Services\Clickers\NodeClickerBuilder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->clicker = Clicker::factory()->create();
    $this->clicker->update(['artwork_path' => "clickers/{$this->clicker->id}/artwork.png"]);
    Storage::disk('local')->put($this->clicker->artwork_path, fakePngBytes());
    $this->builder = app(NodeClickerBuilder::class);
});

it('runs the node script on a job folder and moves the outputs into place', function () {
    $job = null;
    Process::fake(function ($process) use (&$job) {
        $folder = end($process->command);
        $job = json_decode(file_get_contents("{$folder}/job.json"), true);
        file_put_contents("{$folder}/clicker.3mf", '3mf');
        file_put_contents("{$folder}/clicker-stl.zip", 'zip');

        return Process::result();
    });

    $paths = $this->builder->build($this->clicker->load('body'));

    expect($paths)->toBe([
        'threemf' => "clickers/{$this->clicker->id}/clicker-1.3mf",
        'stl_zip' => "clickers/{$this->clicker->id}/clicker-stl-1.zip",
    ]);
    Storage::disk('local')->assertExists(array_values($paths));
    Storage::disk('local')->assertMissing("clickers/{$this->clicker->id}/job-1");
    Process::assertRan(fn ($process) => $process->command[0] === 'node'
        && in_array('--max-old-space-size=768', $process->command, true)
        && str_ends_with($process->command[2], 'build.mjs'));

    expect($job)->toHaveKeys(['settings', 'body', 'bodyFiles', 'title', 'folder'])
        ->and($job['body'])->toHaveKeys(['cap', 'artwork_area', 'max_layer_height', 'min_feature'])
        ->and($job['body'])->not->toHaveKey('switch')
        ->and($job['bodyFiles'][0]['path'])->toEndWith('body.stl')
        ->and($job['bodyFiles'][0]['path'])->toBeFile()
        ->and($job['folder'])->toBe("clicker-{$this->clicker->id}");
});

it('passes a generator body through with no body files', function () {
    $this->clicker->body->update(['model_files' => [], 'config' => ClickerBody::sampleConfig()]);
    $job = null;
    Process::fake(function ($process) use (&$job) {
        $folder = end($process->command);
        $job = json_decode(file_get_contents("{$folder}/job.json"), true);
        file_put_contents("{$folder}/clicker.3mf", '3mf');
        file_put_contents("{$folder}/clicker-stl.zip", 'zip');

        return Process::result();
    });

    $this->builder->build($this->clicker->load('body'));

    expect($job['bodyFiles'])->toBe([])
        ->and($job['body'])->toHaveKeys(['generator', 'keycap', 'cap', 'artwork_area', 'max_layer_height', 'min_feature'])
        ->and($job['body']['generator'])->toBe('mx_keycap')
        ->and($job['body'])->not->toHaveKey('switch');
});

it('turns a failing script into a customer-safe error and cleans up', function () {
    Process::fake(['*' => Process::result('', 'BUILD FAILED: secret detail', 1)]);

    expect(fn () => $this->builder->build($this->clicker->load('body')))
        ->toThrow(ClickerBuildException::class, NodeClickerBuilder::MESSAGE);
    Storage::disk('local')->assertMissing("clickers/{$this->clicker->id}/job-1");
});

it('fails when the script reports success but writes no files', function () {
    Process::fake(['*' => Process::result()]);

    expect(fn () => $this->builder->build($this->clicker->load('body')))->toThrow(ClickerBuildException::class);
});

it('gives every attempt its own folder and file names so overlapping builds do not clobber each other', function () {
    Process::fake(function ($process) {
        $folder = end($process->command);
        file_put_contents("{$folder}/clicker.3mf", basename($folder));
        file_put_contents("{$folder}/clicker-stl.zip", basename($folder));

        return Process::result();
    });
    $disk = Storage::disk('local');
    $dir = "clickers/{$this->clicker->id}";

    $this->clicker->update(['unlock_attempts' => 1]);
    $one = $this->builder->build($this->clicker->fresh()->load('body'));
    $this->clicker->update(['unlock_attempts' => 2]);
    $two = $this->builder->build($this->clicker->fresh()->load('body'));

    expect($one['threemf'])->toBe("{$dir}/clicker-1.3mf")
        ->and($two['threemf'])->toBe("{$dir}/clicker-2.3mf")
        ->and($two['stl_zip'])->toBe("{$dir}/clicker-stl-2.zip")
        ->and($disk->get($one['threemf']))->toBe('job-1')
        ->and($disk->get($two['threemf']))->toBe('job-2');
});

it('moves nothing when only one output was written', function () {
    Process::fake(function ($process) {
        file_put_contents(end($process->command).'/clicker.3mf', '3mf');

        return Process::result();
    });

    expect(fn () => $this->builder->build($this->clicker->load('body')))->toThrow(ClickerBuildException::class);
    Storage::disk('local')->assertMissing("clickers/{$this->clicker->id}/clicker-1.3mf");
    Storage::disk('local')->assertMissing("clickers/{$this->clicker->id}/job-1");
});

it('refuses a body file outside the bodies folder', function () {
    Log::spy();
    $this->clicker->body->update(['model_files' => ['composer.json']]);
    Process::fake();

    expect(fn () => $this->builder->build($this->clicker->load('body')))->toThrow(ClickerBuildException::class);
    Process::assertNothingRan();
    Log::shouldHaveReceived('critical')->once();
});

it('builds a generator keycap body with a circle base shape and a Cap part', function () {
    $this->clicker->body->update(['model_files' => [], 'config' => ClickerBody::sampleConfig()]);
    $this->clicker->update(['settings' => [
        'colors' => [
            ['hex' => '#DC2626', 'height_mm' => 1, 'enabled' => true],
            ['hex' => '#1D4ED8', 'height_mm' => 2, 'enabled' => true],
        ],
        'scale' => 1, 'offset_x' => 0, 'offset_y' => 0, 'outline_mm' => 0, 'outline_hex' => '#111111',
        'base_shape' => 'circle',
    ]]);

    $paths = $this->builder->build($this->clicker->load('body'));

    $file = tempnam(sys_get_temp_dir(), 'mx');
    file_put_contents($file, Storage::disk('local')->get($paths['threemf']));
    $zip = new ZipArchive;
    expect($zip->open($file))->toBeTrue();
    $model = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $model .= $zip->getFromIndex($i);
    }
    $zip->close();
    @unlink($file);

    expect($model)->toContain('name="Cap"');
})->skip(fn () => ! str_starts_with(trim((string) @shell_exec('node --version 2>&1')), 'v'), 'Node is not installed.');

it('builds real print files with node', function () {
    $this->clicker->update(['settings' => [
        'colors' => [
            ['hex' => '#DC2626', 'height_mm' => 1, 'enabled' => true],
            ['hex' => '#1D4ED8', 'height_mm' => 2, 'enabled' => true],
        ],
        'scale' => 1, 'offset_x' => 0, 'offset_y' => 0, 'outline_mm' => 0, 'outline_hex' => '#111111',
    ]]);

    $paths = $this->builder->build($this->clicker->load('body'));

    expect(substr(Storage::disk('local')->get($paths['threemf']), 0, 2))->toBe('PK')
        ->and(substr(Storage::disk('local')->get($paths['stl_zip']), 0, 2))->toBe('PK');
})->skip(fn () => ! str_starts_with(trim((string) @shell_exec('node --version 2>&1')), 'v'), 'Node is not installed.');
