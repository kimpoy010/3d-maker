<?php

namespace App\Services\ModelProviders;

use App\Models\Style;

interface ModelProvider
{
    /**
     * Submit a photo (absolute local path) for generation; returns the provider's job id.
     *
     * @throws TransientProviderException when retrying later may succeed
     * @throws PermanentProviderException when the input can never succeed
     */
    public function start(string $imagePath, Style $style): string;

    /**
     * Check a job. A job that is merely not ready yet is Pending/Running, never an exception.
     *
     * @throws TransientProviderException
     */
    public function status(string $providerJobId): ProviderResult;

    /**
     * Fetch the bytes behind a result URL. Provider URLs expire, so the caller stores the bytes.
     *
     * @throws TransientProviderException
     * @throws PermanentProviderException
     */
    public function download(string $url): string;
}
