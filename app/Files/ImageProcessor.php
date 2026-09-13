<?php

namespace App\Files;

/** Bytes in, bytes out: an implementation knows nothing of File rows or storage. */
interface ImageProcessor
{
    /**
     * @throws ImageProcessingException when these bytes can never become $spec (corrupt, unsupported, over budget)
     * @throws ImageProcessorUnavailableException when the backend cannot answer right now
     */
    public function process(string $bytes, string $mime, ImageSpec $spec): ProcessedImage;

    /** Whether a spec that asks for frames (a canonical, or an animated fit) keeps an animated source animated. */
    public function preservesAnimation(): bool;
}
