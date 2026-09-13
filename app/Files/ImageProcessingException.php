<?php

namespace App\Files;

use RuntimeException;

/** Deterministic for the bytes given: retrying with the same bytes fails the same way. */
class ImageProcessingException extends RuntimeException {}
