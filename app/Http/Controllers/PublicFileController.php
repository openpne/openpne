<?php

namespace App\Http\Controllers;

use App\Files\FileResponse;
use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public: only files explicitly marked public are served, and FilePolicy is still checked as defence
 * in depth. The route binds {file} by its opaque `name` token.
 */
class PublicFileController extends Controller
{
    public function show(Request $request, File $file, FileResponse $responses): Response
    {
        abort_unless($file->explicit_visibility === File::VISIBILITY_PUBLIC, 404);
        abort_unless(Gate::allows('view', $file), 404);

        // Public and immutable (keyed by the opaque name), so it may be cached, unlike authed files.
        $headers = ['Cache-Control' => 'public, max-age=86400'];

        return FileResponse::isRaster($file)
            ? $responses->inline($request, $file, $headers)
            : $responses->attachment($request, $file, $headers);
    }
}
