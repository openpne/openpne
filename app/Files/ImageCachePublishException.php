<?php

namespace App\Files;

use RuntimeException;

/** The cache disk refused a write; the bytes that were to be published are still good. */
class ImageCachePublishException extends RuntimeException {}
