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
 *   3. refuse anything animated;
 *   4. read the dimensions **from the header** and reject an oversized image, by total pixels as well
 *      as by side;
 *   5. only then decode.
 *
 * Everything before step 5 exists because a decoder allocates roughly width × height × 4 bytes per
 * frame before anything can inspect the result, and an out-of-memory kill is not catchable. So the
 * size has to be known to be bounded from data that is cheap to read:
 *
 *  - **Total pixels, not just each side.** The per-side limit alone permits 5000 × 5000 = 100 MB
 *    decoded, which is enough to end a 128 MB worker on its own.
 *  - **A single frame, proven.** Frame count is bounded by neither the wire size nor the dimensions,
 *    and Intervention decodes animations by default — a few-kilobyte GIF can hold hundreds of
 *    frames, each one a full allocation. A card shows one still picture, so ImageContainer walks the
 *    container's own block lengths and the image proceeds only if that walk *proves* one frame; a
 *    parse that gives up is a refusal, not an all-clear.
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

        // Asked as "prove this is one still frame", not "does this look animated" — a parser that
        // gave up must not read as an all-clear. See ImageContainer.
        if ($mime === null || ! ImageContainer::isSafeStill($response->body, $mime)) {
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

            // Deliberately NO explicit visibility. Marking these public would serve them from the
            // login-free route to anyone with the token, and a link card attaches to friends-only
            // diaries and private messages as readily as to open ones. The source URL is not
            // evidence of that: normalisation keeps the query, so the image behind a signed or
            // expiring link gets copied too — and a permanent public copy outlives both that URL's
            // expiry and the body's own visibility rule.
            //
            // So the row is stored fail-closed (FilePolicy denies an unrecognised related entity) and
            // stays undeliverable until delivery is designed against the body that references it.
            $file = $this->uploader->store($upload, relatedType: 'link_card', relatedId: $linkCardId);

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
