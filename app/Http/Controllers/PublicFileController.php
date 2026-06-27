<?php

namespace App\Http\Controllers;

use App\Files\FileStorage;
use App\Models\File;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public delivery of admin-uploaded public assets (explicit_visibility='public'), e.g. an image an
 * operator embeds in custom HTML/CSS. Like BannerImageController this route needs no login; only files
 * explicitly marked public are served — anything else 404s, keeping the rest of the file store behind
 * the authed FileController — and FilePolicy is still checked (it makes these public) as defence in
 * depth. Bound by the opaque `name` token.
 */
class PublicFileController extends Controller
{
    /**
     * MIME types served inline. Anything else is an opaque attachment so a stored file is never
     * interpreted as a same-origin document — the same second-line defense as BannerImageController,
     * kept because this route is public and cacheable.
     */
    private const INLINE_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function show(File $file, FileStorage $storage): StreamedResponse
    {
        abort_unless($file->explicit_visibility === File::VISIBILITY_PUBLIC, 404);
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
            // Public and immutable (keyed by the opaque name), so it may be cached, unlike authed files.
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
