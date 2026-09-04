<?php

namespace App\Http\Controllers;

use App\Files\AppIcon;
use Illuminate\Http\Response;

/**
 * Public, like the manifest that names it: an installable app's icon has to resolve for a guest.
 * Only an install with an uploaded favicon links here (an unbranded shell points at the shipped
 * asset), so a missing favicon is a 404 rather than a redirect back to it.
 */
class AppIconController extends Controller
{
    public function show(string $token, int $size, AppIcon $icons): Response
    {
        $source = $icons->source();

        // The token is a version marker, not a selector: the bytes always come from the current
        // setting, so a stale or forged token reads nothing.
        abort_if($source === null || $source->name !== $token, 404);

        return response($icons->bytes($source, $size), 200, [
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            // Not immutable: a source too small to fill this size answers with the shipped icon,
            // which an upgrade may replace under the same URL.
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
