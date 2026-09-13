<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Files\CanonicalUnavailableException;
use App\Files\ImageBytesOverLimitException;
use App\Files\ImageCache;
use App\Files\ImageProcessorUnavailableException;
use App\Files\ImageTransform;
use App\Models\File;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Authorization is the calling tool's: the pictures handed here are already ones the caller may see.
 */
trait AnswersWithImages
{
    private const SIZES = ['thumbnail', 'original'];

    /** Must stay a rung of config openpne.images.allowed_sizes, or transformFor() answers null. */
    private const THUMBNAIL = 'w640_h640';

    /** ImageTransform::isRaw() reads this geometry as the canonical: the full-size re-encode, never the stored bytes. */
    private const ORIGINAL = 'w_h';

    private const MAX_BYTES = 8 * 1024 * 1024;

    private const NO_THUMBNAIL = 'This site does not offer the thumbnail size these tools ask for. Ask for size=original.';

    /** English like every other message on this wire; the translated userMessage() is for the web forms. */
    private const PROCESSOR_DOWN = 'Image processing is temporarily unavailable. Try again in a moment.';

    private const ALL_REFUSED = 'None of these pictures can be drawn: the site could not process the stored bytes.';

    /** Never a geometry built by hand: fromGeometry() is where the size whitelist is applied. */
    protected function transformFor(?string $size): ?ImageTransform
    {
        return ImageTransform::fromGeometry(($size ?? 'thumbnail') === 'original' ? self::ORIGINAL : self::THUMBNAIL);
    }

    /**
     * @param  list<array{0: int, 1: File}>  $targets  the pictures to answer with, and the numbers they are known by
     */
    protected function answerWithImages(ImageCache $cache, ImageTransform $transform, array $targets): Response|ResponseFactory
    {
        // The stored sizes, which a thumbnail is under and a canonical can exceed: a first cut, not the measure.
        $declared = array_sum(array_map(fn (array $target): int => (int) $target[1]->byte_size, $targets));

        if ($declared > self::MAX_BYTES) {
            return $this->tooLarge();
        }

        $images = [];
        $described = [];
        $read = 0;

        foreach ($targets as [$number, $file]) {
            // byte_size can disagree with the bytes it describes, so what is left of the cap goes down
            // with the request and a row understating its file stops the read rather than the answer.
            try {
                $bytes = $cache->bytes(
                    $file,
                    $transform,
                    (string) $file->imageFormat(),
                    maxBytes: self::MAX_BYTES - $read,
                );
            } catch (ImageBytesOverLimitException) {
                return $this->tooLarge();
            } catch (CanonicalUnavailableException) {
                // A picture the processor refused is reported in its slot; the others still answer.
                $described[] = ['number' => $number, 'unavailable' => true];

                continue;
            } catch (ImageProcessorUnavailableException) {
                return Response::error(self::PROCESSOR_DOWN);
            }

            $read += strlen($bytes);

            // The only bound on a cached thumbnail, which ImageCache serves without reading maxBytes.
            if ($read > self::MAX_BYTES) {
                return $this->tooLarge();
            }

            // Measured off the returned bytes, not off the file row, whose size is the source's.
            $size = @getimagesizefromstring($bytes);
            [$width, $height] = $size !== false && $size[0] > 0 && $size[1] > 0 ? [(int) $size[0], (int) $size[1]] : [null, null];

            $images[] = Response::image($bytes, (string) $file->type);
            $described[] = [
                'number' => $number,
                'width' => $width,
                'height' => $height,
                'mimeType' => (string) $file->type,
                'byteSize' => strlen($bytes),
            ];
        }

        // Nothing drawable is an error, not an empty success a caller could read as "no such picture".
        if ($images === [] && $described !== []) {
            return Response::error(self::ALL_REFUSED);
        }

        return Response::make($images)->withStructuredContent(['images' => $described]);
    }

    private function tooLarge(): Response
    {
        return Response::error(
            'These pictures come to more than the '.intdiv(self::MAX_BYTES, 1024 * 1024)
            .' MB one call may return. Ask for a single one with number, or for size=thumbnail.',
        );
    }
}
