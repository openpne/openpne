<?php

namespace App\Http\Controllers;

use App\Files\AppIcon;
use Illuminate\Http\Response;

/**
 * Serves the home-screen icon derived from the branding favicon. Public, like the manifest and the
 * <head> links that reference it — an installable app's icon has to resolve for a guest.
 *
 * Only an install with an uploaded favicon links here at all: an unbranded shell points straight at
 * the shipped asset, so a missing favicon is a 404 rather than a redirect back to it.
 */
class AppIconController extends Controller
{
    public function show(string $token, int $size, AppIcon $icons): Response
    {
        $source = $icons->source();

        // The token in the URL is a version marker, not a selector: it makes a replaced favicon a
        // different URL — which is what a manifest diff and a client cache key off — while the file
        // itself still comes from the setting, so a stale or forged token reads nothing.
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
