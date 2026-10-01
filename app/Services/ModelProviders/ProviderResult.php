<?php

namespace App\Services\ModelProviders;

final class ProviderResult
{
    public function __construct(
        public readonly ProviderState $state,
        public readonly ?string $modelUrl = null,
        public readonly ?string $thumbnailUrl = null,
        public readonly ?string $error = null,
        public readonly ?int $progress = null,
        public readonly ?string $printModelUrl = null,
    ) {}
}
