<?php

namespace App\Files;

final class ProcessedImage
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mime,
        public readonly int $width,
        public readonly int $height,
        /** null when the processor could not tell. */
        public readonly ?bool $animated,
    ) {}
}
