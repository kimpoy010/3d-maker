<?php

namespace App\Services\ModelProviders;

use App\Enums\Subject;
use App\Models\Style;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class MockProvider implements ModelProvider
{
    public function start(string $imagePath, Style $style): string
    {
        $id = 'mock_'.Str::uuid();
        $rate = (float) config('models.mock.fail_rate');
        $fails = $rate > 0 && random_int(1, 1_000_000) <= $rate * 1_000_000;

        Cache::put($this->key($id), [
            'started_at' => now()->getTimestamp(),
            'subject' => $style->subject->value,
            'fails' => $fails,
        ], now()->addHour());

        return $id;
    }

    public function status(string $providerJobId): ProviderResult
    {
        $job = Cache::get($this->key($providerJobId));

        if (! $job) {
            return new ProviderResult(ProviderState::Failed, error: 'Unknown mock job.');
        }

        $delay = max(0, (int) config('models.mock.delay_seconds'));
        $elapsed = now()->getTimestamp() - $job['started_at'];

        if ($elapsed < $delay) {
            return new ProviderResult(
                $elapsed <= 0 ? ProviderState::Pending : ProviderState::Running,
                progress: (int) floor($elapsed / $delay * 100),
            );
        }

        if ($job['fails']) {
            return new ProviderResult(ProviderState::Failed, error: 'Mock provider simulated a failure.');
        }

        return new ProviderResult(
            ProviderState::Succeeded,
            modelUrl: "mock://model/{$job['subject']}",
            thumbnailUrl: "mock://thumbnail/{$job['subject']}",
            progress: 100,
            printModelUrl: "mock://print/{$job['subject']}",
        );
    }

    public function download(string $url): string
    {
        if (! preg_match('#^mock://(model|thumbnail|print)/(person|pet|object)$#', $url, $m)) {
            throw new PermanentProviderException("Unsupported mock asset URL [{$url}].");
        }

        $subject = Subject::from($m[2]);

        return match ($m[1]) {
            'model' => MockAssets::glb($subject),
            'thumbnail' => MockAssets::thumbnail($subject),
            'print' => MockAssets::stl($subject),
        };
    }

    private function key(string $id): string
    {
        return "mock-provider:{$id}";
    }
}
