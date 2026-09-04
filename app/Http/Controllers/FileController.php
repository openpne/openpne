<?php

namespace App\Http\Controllers;

use App\Files\FileStorage;
use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every backend streams through here so FilePolicy gates each request; a disk backend is never
 * handed out as a bare Storage::url(). The route binds {file} by its opaque `name` token.
 */
class FileController extends Controller
{
    /** Anything else, SVG included, is sent as an attachment so a stored file is never interpreted as a same-origin document. */
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
            // The token names one immutable byte string, so it is the validator.
            'ETag' => '"'.$file->name.'"',
        ];

        // Checked after the policy so a viewer who may no longer see the file is answered 404, never 304.
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
