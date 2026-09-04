<?php

namespace App\Http\Controllers;

use App\Files\FileStorage;
use App\Models\File;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public: OpenPNE 3 banners show to guests. Only files owned by a BannerImage are served, and
 * FilePolicy is still checked as defence in depth.
 */
class BannerImageController extends Controller
{
    /**
     * Anything else is sent as an attachment so a stored file is never interpreted as a same-origin
     * document; the OpenPNE 3 banner-image upgrade copies rows verbatim, so a non-raster type can be
     * present here.
     */
    private const INLINE_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function show(File $file, FileStorage $storage): StreamedResponse
    {
        abort_unless($file->related_entity_type === 'bannerImage', 404);
        abort_unless(Gate::allows('view', $file), 404);

        $inline = in_array($file->type, self::INLINE_IMAGE_TYPES, true);
        $stream = $storage->readStream($file);

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $inline ? $file->type : 'application/octet-stream',
            'Content-Length' => (string) $file->byte_size,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $file->original_filename ?? $file->name,
                $file->name, // ASCII fallback for the opaque token
            ),
            'X-Content-Type-Options' => 'nosniff',
            // Public and immutable (keyed by the opaque name), so it may be cached, unlike authed files.
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
