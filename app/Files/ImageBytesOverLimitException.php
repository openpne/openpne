<?php

namespace App\Files;

use RuntimeException;

/**
 * The stored bytes of an image outgrew the budget its reader passed to ImageCache, and the bytes
 * past that budget were never read. File::byte_size is the only measure available before a read
 * and it can understate what it describes — a corrupt row, an upgrade from OpenPNE 3 — so a
 * caller that could not have answered with the bytes anyway is not made to hold them first.
 */
class ImageBytesOverLimitException extends RuntimeException {}
