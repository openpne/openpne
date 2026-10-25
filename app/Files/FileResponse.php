<?php

namespace App\Files;

use App\Models\File;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A raster is drawn inline from its canonical, never from the stored bytes; anything else is sent as
 * an attachment of the stored bytes so a file is never interpreted as a same-origin document
 * (docs/internals/security.md, "Inline delivery is re-encoded").
 */
class FileResponse
{
    public function __construct(
        private readonly ImageCache $cache,
        private readonly FileStorage $storage,
    ) {}

    public static function isRaster(File $file): bool
    {
        return $file->imageFormat() !== null;
    }

    /**
     * The validator is the canonical's cache key, checked before any bytes are read; $headers carries
     * the route's own Cache-Control.
     *
     * @param  array<string, string>  $headers
     *
     * @throws CanonicalUnavailableException
     * @throws ImageProcessorUnavailableException
     */
    public function inline(Request $request, File $file, array $headers): Response
    {
        $format = (string) $file->imageFormat();
        $headers += [
            'Content-Type' => $file->type,
            // The id, never the uploader's file name nor the token (an OpenPNE 3 name holds slashes).
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, "file-{$file->getKey()}.{$format}"),
            'X-Content-Type-Options' => 'nosniff',
            'ETag' => ImageTransform::raw()->etag($file->name, $format),
        ];

        $unchanged = response('', 200, $headers);
        if ($unchanged->isNotModified($request)) {
            return $unchanged;
        }

        $bytes = $this->cache->canonical($file);

        return response($bytes, 200, $headers + ['Content-Length' => (string) strlen($bytes)]);
    }

    /**
     * The stored bytes as a download; the token (hashed, as an OpenPNE 3 name may hold non-ASCII) is the
     * validator because those bytes never change.
     *
     * @param  array<string, string>  $headers
     */
    public function attachment(Request $request, File $file, array $headers): Response
    {
        $headers += [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) $file->byte_size,
            'Content-Disposition' => self::attachmentDisposition($file),
            'X-Content-Type-Options' => 'nosniff',
            'ETag' => self::etag($file),
        ];

        $unchanged = response('', 200, $headers);
        if ($unchanged->isNotModified($request)) {
            return $unchanged;
        }

        $stream = $this->storage->readStream($file);

        return new StreamedResponse(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, $headers);
    }

    /** The validator for the stored bytes; a hash, since an OpenPNE 3 name may hold characters a header cannot. */
    public static function etag(File $file): string
    {
        return '"'.hash('sha1', $file->name).'"';
    }

    /**
     * The uploader's file name with path separators folded (OpenPNE 3 let a backslash through), over an
     * ASCII fallback built from the id, so a name HeaderUtils refuses never makes a file undeliverable.
     */
    public static function attachmentDisposition(File $file, string $disposition = HeaderUtils::DISPOSITION_ATTACHMENT): string
    {
        $original = $file->original_filename;
        $extension = preg_match('/\\.([A-Za-z0-9]{1,10})$/', (string) $original, $m) === 1 ? '.'.$m[1] : '';
        $fallback = 'file-'.$file->getKey().$extension;

        return HeaderUtils::makeDisposition(
            $disposition,
            $original === null || $original === '' ? $fallback : str_replace(['/', '\\'], '-', $original),
            $fallback,
        );
    }
}
