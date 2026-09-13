<?php

namespace App\Files;

final class ProcessedImage
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mime,
        public readonly int $width,
        public readonly int $height,
        /** null when not judged: the processor could not tell, or was not asked (a variant's frames are nobody's fact). */
        public readonly ?bool $animated,
    ) {}
}
