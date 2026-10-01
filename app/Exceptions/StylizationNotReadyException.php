<?php

namespace App\Exceptions;

use RuntimeException;

/** The stylization is not in a state that allows the requested action. */
class StylizationNotReadyException extends RuntimeException {}
