<?php

use App\Models\Clicker;
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
        'threemf' => "clickers/{$this->clicker->id}/clicker.3mf",
        'stl_zip' => "clickers/{$this->clicker->id}/clicker-stl.zip",
    ]);
    Storage::disk('local')->assertExists(array_values($paths));
    Storage::disk('local')->assertMissing("clickers/{$this->clicker->id}/job");
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

it('turns a failing script into a customer-safe error and cleans up', function () {
    Process::fake(['*' => Process::result('', 'BUILD FAILED: secret detail', 1)]);

    expect(fn () => $this->builder->build($this->clicker->load('body')))
        ->toThrow(ClickerBuildException::class, NodeClickerBuilder::MESSAGE);
    Storage::disk('local')->assertMissing("clickers/{$this->clicker->id}/job");
});

it('fails when the script reports success but writes no files', function () {
    Process::fake(['*' => Process::result()]);

    expect(fn () => $this->builder->build($this->clicker->load('body')))->toThrow(ClickerBuildException::class);
});

it('refuses a body file outside the bodies folder', function () {
    Log::spy();
    $this->clicker->body->update(['model_files' => ['composer.json']]);
    Process::fake();

    expect(fn () => $this->builder->build($this->clicker->load('body')))->toThrow(ClickerBuildException::class);
    Process::assertNothingRan();
    Log::shouldHaveReceived('critical')->once();
});

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
