<?php

namespace App\Http\Controllers;

use App\Files\FileResponse;
use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * OpenPNE 3's opUploadFilePlugin `f/show`: the row is found by id and the extension is ignored, as
 * there (docs/internals/upgrade.md, "OpenPNE 3 upload files").
 */
class LegacyUploadFileController extends Controller
{
    public function show(Request $request, string $file, FileResponse $responses): Response
    {
        // The login redirect lands here through the Inertia router after a sign-in; told to make a full
        // visit, the browser downloads instead of showing the bytes as an error.
        if ($request->hasHeader('X-Inertia')) {
            return Inertia::location($request->fullUrl());
        }

        $found = File::query()->findOrFail((int) strtok($file, '.'));
        abort_unless(Gate::allows('view', $found), 404);

        $headers = ['Cache-Control' => 'private, max-age=0, must-revalidate'];

        return FileResponse::isRaster($found)
            ? $responses->inline($request, $found, $headers)
            : $responses->attachment($request, $found, $headers);
    }
}
