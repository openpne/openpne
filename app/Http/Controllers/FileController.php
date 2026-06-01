<?php

namespace App\Http\Controllers;

use App\Files\FileStorage;
use App\Models\File;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function show(File $file, FileStorage $storage): StreamedResponse
    {
        // 404 (not 403) on deny so the response does not confirm the file exists.
        abort_unless(Gate::allows('view', $file), 404);

        $inline = in_array($file->type, self::INLINE_IMAGE_TYPES, true);
        $stream = $storage->readStream($file);

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $inline ? $file->type : 'application/octet-stream',
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
