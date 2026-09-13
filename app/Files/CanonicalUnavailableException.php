<?php

namespace App\Files;

use RuntimeException;

/** The stored bytes were refused by the processor and a marker remembers it; nothing will be drawn from them. */
class CanonicalUnavailableException extends RuntimeException {}
