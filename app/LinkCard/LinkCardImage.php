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
 * Downloads the image a page named for its card and stores it as a local File.
 *
 * The image is copied rather than hot-linked, for three reasons that all point the same way: a
 * reader's browser never contacts the linked site (so viewing a timeline does not announce itself),
 * the card keeps working when the far end moves or blocks referrers, and the existing thumbnail
 * pipeline can serve it.
 *
 * **The order of checks is the security property here**, not an implementation detail:
 *
 *   1. read at most the byte cap, through the guarded fetcher;
 *   2. identify the real media type from the bytes (finfo), not from Content-Type;
 *   3. read the dimensions **from the header** and reject an oversized image;
 *   4. only then decode.
 *
 * Step 3 before step 4 is what makes this safe against a decompression bomb: a decoder allocates
 * width × height × 4 bytes before anything can look at the result, so a 40000×40000 PNG that is a few
 * kilobytes on the wire exhausts memory during a "validate the image" step that runs too late.
 *
 * Metadata stripping happens exactly once, inside FileUploader — the bytes are handed over as an
 * UploadedFile so there is one strip in the pipeline rather than one here and another there.
 */
final class LinkCardImage
{
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
    ) {}

    /**
     * Fetch $url and store it as the image for card $linkCardId.
     *
     * Returns null whenever the image cannot be had — unreachable, wrong type, too large, undecodable.
     * A card without a picture is still a useful card, so nothing here throws.
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

        // A truncated image is not an image. Unlike HTML, where the useful part comes first, a cut
        // short download decodes to nothing or to garbage.
        if ($response->status !== 200 || $response->truncated || $response->body === '') {
            return null;
        }

        $mime = $this->mediaTypeOf($response->body);

        if ($mime === null) {
            return null;
        }

        $dimensions = $this->headerDimensions($response->body);

        if ($dimensions === null || ! $this->withinLimit($dimensions)) {
            return null;
        }

        // Only now, with the size known to be bounded, is it safe to decode. This confirms the bytes
        // really are the image their header advertises — a header-only forgery or a corrupt download
        // passes finfo and getimagesizefromstring but has nothing behind it, and storing that would
        // give the card a picture that never renders.
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
     * The media type of the bytes themselves.
     *
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

    /** @param  array{int, int}  $dimensions */
    private function withinLimit(array $dimensions): bool
    {
        $limit = (int) config('openpne.images.max_upload_dimension');

        return $dimensions[0] <= $limit && $dimensions[1] <= $limit;
    }

    /**
     * @param  array{int, int}  $dimensions
     * @return array{file: File, width: int, height: int}|null
     */
    private function store(string $bytes, string $mime, array $dimensions, int $linkCardId): ?array
    {
        $path = tempnam(sys_get_temp_dir(), 'linkcard');

        if ($path === false) {
            return null;
        }

        try {
            if (@file_put_contents($path, $bytes) === false) {
                return null;
            }

            // test:true skips the "was this really uploaded" check, which is about PHP's upload
            // machinery and does not apply to bytes this app fetched itself. Everything downstream —
            // including the single metadata strip — is the same path a member upload takes.
            $upload = new UploadedFile($path, 'link-card.'.self::ACCEPTED[$mime], $mime, null, true);

            $file = $this->uploader->store(
                $upload,
                relatedType: 'link_card',
                relatedId: $linkCardId,
                // The source is a public web image and the card appears on pages a logged-out visitor
                // may see (a web-public diary), so inheriting visibility from an owner would hide it.
                explicitVisibility: File::VISIBILITY_PUBLIC,
            );

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
