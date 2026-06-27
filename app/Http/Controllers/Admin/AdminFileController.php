<?php

namespace App\Http\Controllers\Admin;

use App\Files\FileStorage;
use App\Http\Controllers\Controller;
use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves stored file bytes to the admin file monitor (thumbnail preview + download).
 *
 * Unlike FileController (member-gated through FilePolicy), this path is gated by the `admin`
 * guard and intentionally bypasses FilePolicy: an administrator may inspect any uploaded file
 * regardless of its owning entity's visibility. The guard is checked here (not via route
 * middleware) so a non-admin gets a flat 404 rather than a redirect to a member login.
 */
class AdminFileController extends Controller
{
    /**
     * MIME types served inline (so the thumbnail column can render them). Anything else — including
     * SVG, which can run script — is sent as an attachment so a stored file is never interpreted as
     * a same-origin document (stored-XSS defense; mirrors FileController).
     */
    private const INLINE_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function show(Request $request, File $file, FileStorage $storage): StreamedResponse
    {
        // 404 (not 403) for non-admins so the endpoint does not confirm a file exists.
        abort_unless(Auth::guard('admin')->check(), 404);
        abort_unless($storage->exists($file), 404);

        $raster = in_array($file->type, self::INLINE_IMAGE_TYPES, true);
        // Raster images render inline (thumbnail); ?download=1 forces a download. Everything
        // non-raster is always an attachment.
        $inline = $raster && ! $request->boolean('download');

        $stream = $storage->readStream($file);

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $raster ? $file->type : 'application/octet-stream',
            'Content-Length' => (string) $file->byte_size,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $file->original_filename ?? $file->name,
                $file->name, // ASCII fallback for the opaque token
            ),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }
}
