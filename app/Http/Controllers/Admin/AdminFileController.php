<?php

namespace App\Http\Controllers\Admin;

use App\Files\FileResponse;
use App\Files\FileStorage;
use App\Files\ImageIntake;
use App\Files\ImageSourceLimit;
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
    public function show(Request $request, File $file, FileStorage $storage): Response
    {
        // 404 (not 403) for non-admins so the endpoint does not confirm a file exists.
        abort_unless(Auth::guard('admin')->check(), 404);
        abort_unless($storage->exists($file), 404);

        $cache = [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            // The token names one immutable byte string, as on FileController.
            'ETag' => '"'.$file->name.'"',
        ];

        $unchanged = response('', 200, $cache);
        if ($unchanged->isNotModified($request)) {
            return $unchanged;
        }

        $stream = $storage->readStream($file);
        $head = (string) fread($stream, ImageSourceLimit::SNIFF_BYTES);

        // Labelled by what the bytes are, not by `type` (the canonical's): a raster container the app
        // reads is inline, anything else an attachment, so a stored file is never a same-origin document.
        $sniffed = (new \finfo(FILEINFO_MIME_TYPE))->buffer($head);
        // The sidecar's list whatever the processor is, so a HEIC stored under it stays inline after a switch to GD.
        $raster = FileResponse::isRaster($file) && is_string($sniffed) && in_array($sniffed, ImageIntake::imgproxy()->mimes(), true);
        $inline = $raster && ! $request->boolean('download');

        $headers = $cache + [
            'Content-Type' => $raster ? $sniffed : 'application/octet-stream',
            'Content-Length' => (string) $file->byte_size,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $file->original_filename ?? $file->name,
                $file->name, // ASCII fallback for the opaque token
            ),
        ];

        return response()->stream(function () use ($head, $stream): void {
            echo $head;
            fpassthru($stream);
            fclose($stream);
        }, 200, $headers);
    }
}
