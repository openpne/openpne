<?php

namespace App\Files;

/**
 * Fails closed: the original bytes are never passed through
 * (docs/internals/security.md, "Uploaded image metadata").
 */
class ImageMetadataStripException extends ImageProcessingException {}
