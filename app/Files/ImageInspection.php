<?php

declare(strict_types=1);

namespace App\Files;

/**
 * The result of a container walk: what the image is, and the shape a later stage needs to know
 * before it allocates anything.
 */
final class ImageInspection
{
    private function __construct(
        public readonly ImageStructure $structure,
        public readonly int $width = 0,
        public readonly int $height = 0,
        public readonly int $frames = 0,
    ) {}

    public static function invalid(): self
    {
        return new self(ImageStructure::Invalid);
    }

    public static function of(ImageStructure $structure, int $width, int $height, int $frames): self
    {
        return new self($structure, $width, $height, $frames);
    }

    public function isValid(): bool
    {
        return $this->structure !== ImageStructure::Invalid;
    }

    public function isAnimated(): bool
    {
        return $this->structure === ImageStructure::Animated;
    }

    /** Every frame is a full canvas once decoded, whatever rectangle the container stored. */
    public function decodedPixels(): int
    {
        return $this->width * $this->height * max($this->frames, 1);
    }
}
