<?php

namespace App\Services\Clickers;

use App\Models\Clicker;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Builds the print files by running resources/clicker/build.mjs in Node. */
class NodeClickerBuilder implements ClickerBuilder
{
    /** Shown to the customer on any failure; the real reason goes to the log. */
    public const MESSAGE = 'We could not build your files.';

    public function build(Clicker $clicker): array
    {
        $disk = Storage::disk('local');
        $directory = $clicker->directory();
        // Each paid attempt has its own folder and file names, so a stale job only ever touches its own files.
        $attempt = max(1, (int) $clicker->unlock_attempts);
        $job = "{$directory}/job-{$attempt}";
        $outputs = ['threemf' => ['clicker.3mf', "clicker-{$attempt}.3mf"], 'stl_zip' => ['clicker-stl.zip', "clicker-stl-{$attempt}.zip"]];

        $disk->deleteDirectory($job);
        $disk->makeDirectory($job);

        try {
            $this->prepare($clicker, $job);
            $this->run($clicker, $disk->path($job));

            // Both outputs must exist before either is moved.
            foreach ($outputs as [$written]) {
                if (! $disk->exists("{$job}/{$written}")) {
                    Log::error('The clicker build wrote no output file.', ['clicker_id' => $clicker->id, 'missing' => $written]);

                    throw new ClickerBuildException(self::MESSAGE);
                }
            }

            $paths = [];
            foreach ($outputs as $key => [$written, $final]) {
                $disk->delete("{$directory}/{$final}");
                $disk->move("{$job}/{$written}", "{$directory}/{$final}");
                $paths[$key] = "{$directory}/{$final}";
            }

            return $paths;
        } finally {
            $disk->deleteDirectory($job);
        }
    }

    /** Write job.json and copy the artwork into the job folder. */
    private function prepare(Clicker $clicker, string $job): void
    {
        $disk = Storage::disk('local');
        $body = $clicker->body;
        $root = realpath(resource_path('clicker/bodies'));

        $bodyFiles = [];
        // A generator body has no model files: its part comes from the geometry module.
        foreach ($body->model_files ?? [] as $relative) {
            $absolute = realpath(base_path($relative));

            // Only files inside the bodies folder may ever be handed to the script.
            if ($root === false || $absolute === false || ! str_starts_with($absolute, $root.DIRECTORY_SEPARATOR)) {
                Log::critical('A clicker body file is missing or outside the bodies folder.', ['body_id' => $body->id, 'file' => $relative]);

                throw new ClickerBuildException(self::MESSAGE);
            }

            $bodyFiles[] = ['path' => $absolute, 'name' => 'Body'];
        }

        if (! $clicker->artwork_path || ! $disk->exists($clicker->artwork_path)) {
            Log::error('The clicker artwork file is missing.', ['clicker_id' => $clicker->id]);

            throw new ClickerBuildException(self::MESSAGE);
        }

        $disk->copy($clicker->artwork_path, "{$job}/artwork.png");
        $disk->put("{$job}/job.json", json_encode([
            'settings' => $clicker->settings,
            'body' => Arr::only($body->config, ['generator', 'keycap', 'cap', 'artwork_area', 'max_layer_height', 'min_feature']),
            'bodyFiles' => $bodyFiles,
            'title' => Str::limit($clicker->name, 60, ''),
            'folder' => "clicker-{$clicker->id}",
        ], JSON_THROW_ON_ERROR));
    }

    private function run(Clicker $clicker, string $jobPath): void
    {
        try {
            $result = Process::path(base_path())
                ->timeout((int) config('clicker.build_timeout_seconds'))
                ->run([(string) config('clicker.node_binary'), '--max-old-space-size=768', resource_path('clicker/build.mjs'), $jobPath]);
        } catch (ProcessTimedOutException) {
            Log::error('The clicker build timed out.', ['clicker_id' => $clicker->id]);

            throw new ClickerBuildException(self::MESSAGE);
        }

        if ($result->failed()) {
            Log::error('The clicker build failed.', [
                'clicker_id' => $clicker->id,
                'exit_code' => $result->exitCode(),
                'error' => Str::limit(trim($result->errorOutput()), 300),
            ]);

            throw new ClickerBuildException(self::MESSAGE);
        }
    }
}
