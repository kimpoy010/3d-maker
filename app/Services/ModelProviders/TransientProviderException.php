<?php

namespace App\Services\ModelProviders;

/** Network errors, 5xx, rate limits: retrying later may work. */
class TransientProviderException extends ProviderException {}
