<?php

namespace App\Http\Controllers;

use App\Files\ImageCache;
use App\Files\ImageTransform;
use App\Models\File;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Serves thumbnail variants at the OpenPNE 3-compatible
 * `/cache/img/{format}/w{W}_h{H}[_sq]/{name}.{ext}` URL, so old image links keep
 * working. Like FileController, every request is gated by FilePolicy — a member
 * avatar thumbnail is as private as the original.
 */
class ImageController extends Controller
{
    public function show(string $format, string $geometry, string $name, string $ext, ImageCache $cache): Response
    {
        // The OpenPNE 3 URL repeats the format in the directory and the extension.
        abort_unless($format === $ext, 404);

        $file = File::query()->where('name', $name)->first();
        // 404 (not 403) on a missing file or a denied policy so neither is distinguishable.
        abort_unless($file !== null && Gate::allows('view', $file), 404);

        // The requested format must be the file's actual image format.
        $imageFormat = $file->imageFormat();
        abort_unless($imageFormat === $format, 404);

        $transform = ImageTransform::fromGeometry($geometry);
        abort_unless($transform !== null, 404);

        $bytes = $cache->bytes($file, $transform, $imageFormat);

        return response($bytes, 200, [
            'Content-Type' => $file->type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
