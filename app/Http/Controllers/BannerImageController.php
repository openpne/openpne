<?php

namespace App\Http\Controllers;

use App\Files\FileStorage;
use App\Models\File;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public delivery of banner images. OpenPNE 3 banners show to guests (e.g. the before-login banner),
 * so unlike the authed FileController this route needs no login. Only files owned by a BannerImage are
 * served — anything else 404s, keeping the rest of the file store behind FileController — and
 * FilePolicy is still checked (it makes banner images public) as defence in depth.
 */
class BannerImageController extends Controller
{
    public function show(File $file, FileStorage $storage): StreamedResponse
    {
        abort_unless($file->related_entity_type === 'bannerImage', 404);
        abort_unless(Gate::allows('view', $file), 404);

        $stream = $storage->readStream($file);

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $file->type,
            'Content-Length' => (string) $file->byte_size,
            'X-Content-Type-Options' => 'nosniff',
            // Public and immutable (keyed by the opaque name), so it may be cached, unlike authed files.
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
