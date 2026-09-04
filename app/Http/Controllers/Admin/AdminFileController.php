<?php

namespace App\Http\Controllers\Admin;

use App\Files\FileStorage;
use App\Http\Controllers\Controller;
use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gated by the `admin` guard alone, deliberately not FilePolicy: an administrator may inspect any
 * uploaded file. The guard is checked in the action so a non-admin gets a flat 404 rather than a
 * redirect to a member login.
 */
class AdminFileController extends Controller
{
    /**
     * Anything else, SVG included, is sent as an attachment so a stored file is never interpreted as a
     * same-origin document; OpenPNE 3 rows are upgraded verbatim, so a non-raster type can be present.
     */
    private const INLINE_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function show(Request $request, File $file, FileStorage $storage): Response
    {
        // 404 (not 403) for non-admins so the endpoint does not confirm a file exists.
        abort_unless(Auth::guard('admin')->check(), 404);
        abort_unless($storage->exists($file), 404);

        $raster = in_array($file->type, self::INLINE_IMAGE_TYPES, true);
        $inline = $raster && ! $request->boolean('download');

        $headers = [
            'Content-Type' => $raster ? $file->type : 'application/octet-stream',
            'Content-Length' => (string) $file->byte_size,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $file->original_filename ?? $file->name,
                $file->name, // ASCII fallback for the opaque token
            ),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            // The token names one immutable byte string, as on FileController.
            'ETag' => '"'.$file->name.'"',
        ];

        $unchanged = response('', 200, $headers);
        if ($unchanged->isNotModified($request)) {
            return $unchanged;
        }

        $stream = $storage->readStream($file);

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, $headers);
    }
}
