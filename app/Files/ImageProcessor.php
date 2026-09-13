<?php

namespace App\Files;

/** Bytes in, bytes out: an implementation knows nothing of File rows or storage. */
interface ImageProcessor
{
    /**
     * $mime is the caller's record of the bytes, advisory only: an implementation judges the bytes
     * themselves, since a stored file's `type` is its canonical's and may not name its container.
     *
     * @throws ImageProcessingException when these bytes can never become $spec (corrupt, unsupported, over budget)
     * @throws ImageProcessorUnavailableException when the backend cannot answer right now
     */
    public function process(string $bytes, string $mime, ImageSpec $spec): ProcessedImage;

    public function intake(): ImageIntake;

    /** Whether a spec that asks for frames (a canonical, or an animated fit) keeps an animated source animated. */
    public function preservesAnimation(): bool;
}
