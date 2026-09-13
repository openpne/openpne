<?php

namespace App\Http\Controllers;

use App\Files\FileResponse;
use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public: OpenPNE 3 banners show to guests. Only files owned by a BannerImage are served, and
 * FilePolicy is still checked as defence in depth.
 */
class BannerImageController extends Controller
{
    public function show(Request $request, File $file, FileResponse $responses): Response
    {
        abort_unless($file->related_entity_type === 'bannerImage', 404);
        abort_unless(Gate::allows('view', $file), 404);

        // Public and immutable (keyed by the opaque name), so it may be cached, unlike authed files.
        $headers = ['Cache-Control' => 'public, max-age=86400'];

        return FileResponse::isRaster($file)
            ? $responses->inline($request, $file, $headers)
            : $responses->attachment($request, $file, $headers);
    }
}
