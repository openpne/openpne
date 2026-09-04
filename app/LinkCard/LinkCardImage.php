<?php

declare(strict_types=1);

namespace App\LinkCard;

use App\Files\FileUploader;
use App\Models\File;
use App\Outbound\OutboundException;
use App\Outbound\SafeHttpFetcher;
use Illuminate\Http\UploadedFile;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Copies a card's image into a local File rather than hot-linking it. The order of checks in
 * `import()` (byte cap, real media type, animation, header dimensions by side and total pixels,
 * then decode) is the security property, because a decoder allocates width × height × 4 bytes per
 * frame and an out-of-memory kill is not catchable; see docs/internals/link-cards.md.
 */
final class LinkCardImage
{
    /**
     * Not a morph alias, deliberately: `link_card` resolves to no model, and `FilePolicy` denies a
     * related entity it cannot resolve, so the generic file route refuses these bytes whatever else
     * changes. Written down once because {@see InternalCardRow} deletes by it.
     */
    public const RELATED_TYPE = 'link_card';

    /** Formats the card renders. SVG is absent deliberately: it is a document, not a picture. */
    private const ACCEPTED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly SafeHttpFetcher $fetcher,
        private readonly FileUploader $uploader,
        private readonly ImageManager $images,
        /**
         * Where fetched bytes are staged before the uploader takes them; the system temp directory
         * unless told otherwise. Injectable so a test can watch a directory it owns: the staged names
         * say nothing about which process wrote them, so counting them anywhere shared counts every
         * other test worker's in-flight files too.
         */
        private readonly ?string $stagingDir = null,
    ) {}

    /**
     * Null whenever the image cannot be had; a card without a picture is still a useful card, so
     * nothing here throws.
     *
     * @param  float|null  $deadline  The job's remaining budget.
     * @return array{file: File, width: int, height: int}|null
     */
    public function import(string $url, int $linkCardId, ?float $deadline = null): ?array
    {
        try {
            $response = $this->fetcher->get($url, $this->maxBytes(), $deadline);
        } catch (OutboundException) {
            return null;
        }

        // A truncated image is not an image: unlike HTML, where the useful part comes first, a
        // cut-short download decodes to nothing or to garbage.
        if ($response->status !== 200 || $response->truncated || $response->body === '') {
            return null;
        }

        $mime = $this->mediaTypeOf($response->body);

        // Asked as "prove this is one still frame", not "does this look animated", so a parser that
        // gave up is not an all-clear.
        if ($mime === null || ! ImageContainer::isSafeStill($response->body, $mime)) {
            return null;
        }

        $dimensions = $this->headerDimensions($response->body);

        if ($dimensions === null || ! $this->withinLimit($dimensions)) {
            return null;
        }

        // Only now, with the size known bounded, is decoding safe; it also confirms the bytes are the
        // image their header advertises, since a header-only forgery passes finfo and
        // getimagesizefromstring.
        if (! $this->isDecodable($response->body)) {
            return null;
        }

        return $this->store($response->body, $mime, $dimensions, $linkCardId);
    }

    /**
     * Whether the bytes actually decode.
     *
     * Called strictly after the dimension check, never before: a decoder allocates
     * width × height × 4 bytes up front, so decoding to find out how big something is hands a
     * few-kilobyte file the ability to exhaust memory.
     */
    private function isDecodable(string $bytes): bool
    {
        try {
            $this->images->decode($bytes);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Content-Type is a claim by the far end; finfo reads the actual signature. A file served as
     * `image/png` that is really something else must not become a stored image.
     */
    private function mediaTypeOf(string $bytes): ?string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return is_string($mime) && isset(self::ACCEPTED[$mime]) ? $mime : null;
    }

    /**
     * Width and height read from the image header, without decoding the pixels.
     *
     * getimagesizefromstring parses the header only, which is precisely why it runs before any
     * decode: it is the cheap look that lets an oversized image be refused for free.
     *
     * @return array{int, int}|null
     */
    private function headerDimensions(string $bytes): ?array
    {
        $info = @getimagesizefromstring($bytes);

        if ($info === false || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
            return null;
        }

        return [(int) $info[0], (int) $info[1]];
    }

    /**
     * @param  array{int, int}  $dimensions
     */
    private function withinLimit(array $dimensions): bool
    {
        $side = (int) config('openpne.images.max_upload_dimension');
        $pixels = (int) config('openpne.outbound.max_image_pixels');

        // Both, because either alone leaves a hole: a 1 x 50000000 strip passes a pixel-count check
        // on neither side, and a 5000 x 5000 square passes the per-side check while decoding to
        // 100 MB.
        return $dimensions[0] <= $side
            && $dimensions[1] <= $side
            && $pixels >= $dimensions[0] * $dimensions[1];
    }

    /**
     * @param  array{int, int}  $dimensions
     * @return array{file: File, width: int, height: int}|null
     */
    private function store(string $bytes, string $mime, array $dimensions, int $linkCardId): ?array
    {
        $path = tempnam($this->stagingDir ?? sys_get_temp_dir(), 'linkcard');

        if ($path === false) {
            return null;
        }

        try {
            if (@file_put_contents($path, $bytes) === false) {
                return null;
            }

            // `test: true` skips the was-this-really-uploaded check, which is about PHP's upload
            // machinery; everything downstream, the single metadata strip included, is the path a
            // member upload takes.
            $upload = new UploadedFile($path, 'link-card.'.self::ACCEPTED[$mime], $mime, null, true);

            // Deliberately no explicit visibility: a card attaches to friends-only bodies as readily
            // as open ones and the source URL is no evidence otherwise, so the row stays fail-closed
            // and is served only through `LinkCardImageController`.
            $file = $this->uploader->store($upload, relatedType: self::RELATED_TYPE, relatedId: $linkCardId);

            return ['file' => $file, 'width' => $dimensions[0], 'height' => $dimensions[1]];
        } catch (Throwable) {
            return null;
        } finally {
            // The temp file is this class's to clean up on every path, including the failures above.
            @unlink($path);
        }
    }

    private function maxBytes(): int
    {
        return (int) config('openpne.outbound.max_image_bytes');
    }
}
