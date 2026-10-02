<?php

namespace App\Console\Commands;

use App\Models\Style;
use App\Services\ImageSanitizer;
use App\Services\ModelProviders\ModelProvider;
use App\Services\ModelProviders\ProviderResult;
use App\Services\ModelProviders\ProviderState;
use App\Services\Stylizers\ImageStylizer;
use App\Services\Stylizers\StylizedImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SmokeProviders extends Command
{
    protected $signature = 'providers:smoke
        {photo : Path to a photo on this machine}
        {style : A style id, or a look such as chibi, clay or sleepy}
        {--subject=person : Subject to use when the style is given as a look}
        {--skip-3d : Only run the restyle step}';

    protected $description = 'Run ONE real restyle and ONE real 3D build with the configured providers (costs money)';

    /** Seconds between 3D status checks. Public and static only so tests can shrink them. */
    public static int $pollSeconds = 5;

    /** Longest wait for the 3D build. Public and static only so tests can shrink it. */
    public static int $maxWaitSeconds = 600;

    public function handle(ImageSanitizer $sanitizer, ImageStylizer $stylizer, ModelProvider $provider): int
    {
        $photo = (string) $this->argument('photo');

        if (! is_file($photo)) {
            $this->error("Photo not found: {$photo}");

            return self::FAILURE;
        }

        $style = $this->findStyle();

        if (! $style) {
            $this->error('No such style. Use a style id, or a look like chibi (with --subject).');

            return self::FAILURE;
        }

        if (! $this->confirm('This makes paid API calls with your configured keys. Continue?')) {
            return self::FAILURE;
        }

        $disk = Storage::disk('local');
        $dir = 'smoke/'.now()->format('Ymd-His');
        $this->line('Providers: restyle='.config('stylizer.provider').', 3D='.config('models.provider'));

        try {
            $disk->put("{$dir}/original.jpg", $sanitizer->sanitize($photo));

            $started = microtime(true);
            $this->info("Restyling with {$style->subject->value} {$style->look}...");
            $png = $stylizer->stylize($disk->path("{$dir}/original.jpg"), $style);
            $restyled = "{$dir}/restyled.".StylizedImage::extension($png);
            $disk->put($restyled, $png);
            $this->line(sprintf('  done in %.1fs, %s KB -> %s', microtime(true) - $started, number_format(strlen($png) / 1024, 1), $disk->path($restyled)));

            if ($this->option('skip-3d')) {
                return self::SUCCESS;
            }

            $disk->put("{$dir}/restyled.jpg", $sanitizer->sanitize($disk->path($restyled)));

            $this->info('Building the 3D model...');
            $taskId = $provider->start($disk->path("{$dir}/restyled.jpg"), $style);
            $this->line("  task: {$taskId}");

            $result = $this->waitFor($provider, $taskId);

            if (! in_array($result->state, [ProviderState::Succeeded, ProviderState::Failed], true)) {
                $this->error('Timed out waiting for the 3D build.');

                return self::FAILURE;
            }

            if ($result->state === ProviderState::Failed) {
                $this->error('3D build failed: '.($result->error ?? 'unknown error'));

                return self::FAILURE;
            }

            $disk->put("{$dir}/model.glb", $provider->download((string) $result->modelUrl));
            $result->printModelUrl && $disk->put("{$dir}/print.stl", $provider->download($result->printModelUrl));
            $result->thumbnailUrl && $disk->put("{$dir}/thumbnail.png", $provider->download($result->thumbnailUrl));
            $this->info('Saved to '.$disk->path($dir));
        } catch (Throwable $e) {
            $this->error(class_basename($e).': '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function findStyle(): ?Style
    {
        $given = (string) $this->argument('style');

        return ctype_digit($given)
            ? Style::find((int) $given)
            : Style::where('look', $given)->where('subject', $this->option('subject'))->first();
    }

    private function waitFor(ModelProvider $provider, string $taskId): ProviderResult
    {
        $deadline = time() + self::$maxWaitSeconds;

        while (true) {
            $result = $provider->status($taskId);

            $this->line('  '.strtolower($result->state->name).($result->progress !== null ? " {$result->progress}%" : ''));

            if (in_array($result->state, [ProviderState::Succeeded, ProviderState::Failed], true) || time() > $deadline) {
                return $result;
            }

            // A manual command, not a queue worker: sleeping here is fine.
            sleep(self::$pollSeconds);
        }
    }
}
