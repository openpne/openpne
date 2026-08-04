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
    public function show(int $size, AppIcon $icons): Response
    {
        abort_unless(in_array($size, AppIcon::SIZES, true), 404);

        $source = $icons->source();
        abort_if($source === null, 404);

        return response($icons->bytes($source, $size), 200, [
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            // Public but not immutable: unlike a token-keyed file URL this one is stable across
            // uploads, so it has to expire for a replaced favicon to show up.
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
