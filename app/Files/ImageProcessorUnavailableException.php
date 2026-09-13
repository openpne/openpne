<?php

namespace App\Files;

use RuntimeException;

/** Transient: the backend could not be reached or answered with an outage, not a verdict on the bytes. */
class ImageProcessorUnavailableException extends RuntimeException {}
