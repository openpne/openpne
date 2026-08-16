<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Files\ImageBytesOverLimitException;
use App\Files\ImageCache;
use App\Files\ImageDimensions;
use App\Files\ImageTransform;
use App\Models\File;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Answering with picture bytes, under a cap decided before any of them are in memory.
 *
 * Bytes and their bound, and nothing else: which pictures a caller may have — the feature's own
 * switch, the gate on the record they hang off, what a named slot holding nothing answers — is the
 * tool's, which settles its targets first and hands them here.
 */
trait AnswersWithImages
{
    private const SIZES = ['thumbnail', 'original'];

    /** A rung of the existing ladder (config openpne.images.allowed_sizes), fitted rather than cropped. */
    private const THUMBNAIL = 'w640_h640';

    /** The stored bytes as they are: ImageTransform::isRaw() sends ImageCache straight past the decoder. */
    private const ORIGINAL = 'w_h';

    /**
     * The most bytes one call may answer with. A picture costs a client far more context than a
     * message does, and a thread of photographs would otherwise fill it in a single call.
     */
    private const MAX_BYTES = 8 * 1024 * 1024;

    private const NO_THUMBNAIL = 'This site does not offer the thumbnail size these tools ask for. Ask for size=original.';

    /**
     * The geometry a `size` argument names, or null where an operator has taken the thumbnail rung
     * out of the whitelist — {@see self::NO_THUMBNAIL}. Never a geometry built by hand:
     * fromGeometry() is where the size whitelist is applied, and a caller-driven size is exactly
     * what it exists to keep out of the cache.
     */
    protected function transformFor(?string $size): ?ImageTransform
    {
        return ImageTransform::fromGeometry(($size ?? 'thumbnail') === 'original' ? self::ORIGINAL : self::THUMBNAIL);
    }

    /**
     * @param  list<array{0: int, 1: File}>  $targets  the pictures to answer with, and the numbers they are known by
     */
    protected function answerWithImages(ImageCache $cache, ImageTransform $transform, array $targets): Response|ResponseFactory
    {
        // The declared sizes are the only measure available before any bytes are in memory, and
        // answering before they are is the whole point of a cap. They are the originals' sizes even
        // when thumbnails were asked for: conservative rather than exact, a thumbnail being smaller
        // than its source and never larger.
        $declared = array_sum(array_map(fn (array $target): int => (int) $target[1]->byte_size, $targets));

        if ($declared > self::MAX_BYTES) {
            return $this->tooLarge();
        }

        $images = [];
        $described = [];
        $read = 0;

        foreach ($targets as [$number, $file]) {
            // byte_size is metadata, and metadata can disagree with the bytes it describes. What is
            // left of the cap goes down with the request so a row that understates its file stops the
            // read there, rather than the file arriving whole and being measured afterwards.
            try {
                $bytes = $cache->bytes(
                    $file,
                    $transform,
                    (string) $file->imageFormat(),
                    maxBytes: self::MAX_BYTES - $read,
                );
            } catch (ImageBytesOverLimitException) {
                return $this->tooLarge();
            }

            $read += strlen($bytes);

            // Belt on the bound above, and where a cached thumbnail — served without one — is counted.
            // Nothing partial goes back either way: an answer trimmed to fit is one the caller cannot
            // tell from a whole one.
            if ($read > self::MAX_BYTES) {
                return $this->tooLarge();
            }

            // Measured off what is being returned rather than read off the file row, whose size is the
            // source's. Null when these bytes do not decode here at all.
            [$width, $height] = ImageDimensions::fromBytes($bytes) ?? [null, null];

            $images[] = Response::image($bytes, (string) $file->type);
            $described[] = [
                'number' => $number,
                'width' => $width,
                'height' => $height,
                'mimeType' => (string) $file->type,
                'byteSize' => strlen($bytes),
            ];
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
