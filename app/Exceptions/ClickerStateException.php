<?php

namespace App\Exceptions;

use RuntimeException;

/** The design is in a state that does not allow the requested action (locked, no artwork, building...). */
class ClickerStateException extends RuntimeException {}
