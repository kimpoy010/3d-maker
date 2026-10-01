<?php

namespace App\Services\Stylizers;

use App\Models\Style;
use App\Services\ModelProviders\PermanentProviderException;
use App\Services\ModelProviders\TransientProviderException;

interface ImageStylizer
{
    /**
     * Restyle a photo (absolute local path) using the style's prompt. Returns PNG bytes.
     *
     * Exception messages are shown to the customer, so they must be plain and safe.
     *
     * @throws TransientProviderException when retrying later may succeed
     * @throws PermanentProviderException when the photo or request can never succeed
     */
    public function stylize(string $photoPath, Style $style): string;
}
