<?php

namespace App\Services\Clickers;

use RuntimeException;

/** A build failed. The message is shown to the customer, so it must be plain and safe. */
class ClickerBuildException extends RuntimeException {}
