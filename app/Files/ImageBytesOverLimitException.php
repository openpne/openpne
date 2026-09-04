<?php

namespace App\Files;

use RuntimeException;

/**
 * The stored bytes of an image outgrew the budget its reader passed to `ImageCache`, and the bytes
 * past that budget were never read. `File::byte_size` can understate the stored length (a corrupt
 * row, an upgrade from OpenPNE 3), so the budget is enforced on the read itself.
 */
class ImageBytesOverLimitException extends RuntimeException {}
