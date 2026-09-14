<?php

namespace App\Http\Controllers;

use App\Files\ImageCache;
use App\Files\ImageProcessor;
use App\Files\ImageSpec;
use App\Files\ImageTransform;
use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Serves thumbnail variants at the OpenPNE 3-compatible
 * `/cache/img/{format}/w{W}_h{H}[_sq|_a]/{name}.{ext}` URL, so old image links keep
 * working. Like FileController, every request is gated by FilePolicy — a member
 * avatar thumbnail is as private as the original.
 */
class ImageController extends Controller
{
    public function show(Request $request, string $format, string $geometry, string $name, string $ext, ImageCache $cache, ImageProcessor $processor): Response
    {
        // The OpenPNE 3 URL repeats the format in the directory and the extension.
        abort_unless($format === $ext, 404);

        $file = File::query()->where('name', $name)->first();
        // 404 (not 403) on a missing file or a denied policy so neither is distinguishable.
        abort_unless($file !== null && Gate::allows('view', $file), 404);

        // The format asked for is the file's own or WebP, the one format every browser shows and both
        // processors write (docs/internals/images.md, "A variant may be asked for as WebP").
        $imageFormat = $file->imageFormat();
        abort_unless($imageFormat !== null && in_array($format, [$imageFormat, 'webp'], true), 404);

        $transform = ImageTransform::fromGeometry($geometry);
        abort_unless($transform !== null, 404);

        // The canonical is re-encoded, never transcoded.
        abort_unless(! $transform->isRaw() || $format === $imageFormat, 404);

        // Before the validator: a picture not known to animate has no animated variant, not a still under an ETag that would outlive the answer.
        abort_unless(! $transform->animated || $file->animated === true, 404);

        // A processor that cannot write WebP is asked for none, so a host without it answers as today.
        abort_unless($format === $imageFormat || $processor->intake()->writesWebp(), 404);

        // Checked after the policy so a viewer who may no longer see the file is answered 404, never 304.
        $response = response('', 200, [
            'Content-Type' => ImageSpec::mimeFor($format),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
            'ETag' => $transform->etag($file->name, $format),
        ]);

        if ($response->isNotModified($request)) {
            return $response;
        }

        return $response->setContent($cache->bytes($file, $transform, $format));
    }
}
