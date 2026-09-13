<?php

namespace App\Http\Controllers;

use App\Files\FileResponse;
use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every backend answers through here so FilePolicy gates each request; a disk backend is never
 * handed out as a bare Storage::url(). The route binds {file} by its opaque `name` token.
 */
class FileController extends Controller
{
    public function show(Request $request, File $file, FileResponse $responses): Response
    {
        // 404 (not 403) on deny so the response does not confirm the file exists.
        abort_unless(Gate::allows('view', $file), 404);

        // Checked after the policy so a viewer who may no longer see the file is answered 404, never 304.
        $headers = ['Cache-Control' => 'private, max-age=0, must-revalidate'];

        return FileResponse::isRaster($file)
            ? $responses->inline($request, $file, $headers)
            : $responses->attachment($request, $file, $headers);
    }
}
