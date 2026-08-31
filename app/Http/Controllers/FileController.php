<?php

namespace App\Http\Controllers;

use App\Files\FileStorage;
use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves stored file bytes. Every backend streams through here so FilePolicy gates
 * each request — disk backends are never handed out as a bare Storage::url(), which
 * would bypass the policy. The route binds {file} by its opaque `name` token.
 */
class FileController extends Controller
{
    /**
     * MIME types served inline. Anything else — including SVG, which can run script
     * — is sent as an opaque attachment so a stored file is never interpreted as a
     * same-origin document (stored-XSS defense; the upload validation also rejects
     * non-raster types, this is the second line).
     */
    private const INLINE_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function show(Request $request, File $file, FileStorage $storage): Response
    {
        // 404 (not 403) on deny so the response does not confirm the file exists.
        abort_unless(Gate::allows('view', $file), 404);

        $inline = in_array($file->type, self::INLINE_IMAGE_TYPES, true);
        $headers = [
            'Content-Type' => $inline ? $file->type : 'application/octet-stream',
            'Content-Length' => (string) $file->byte_size,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $file->original_filename ?? $file->name,
                $file->name, // ASCII fallback for the opaque token
            ),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            // The token names one immutable byte string, so it is the validator. Should delivery ever
            // change the bytes under a token, the tag has to take a generation, as ImageTransform's does.
            'ETag' => '"'.$file->name.'"',
        ];

        // Validated before the store is opened: a 304 sends no bytes. After the policy, so a viewer
        // who may no longer see the file is answered 404, never "unchanged".
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
