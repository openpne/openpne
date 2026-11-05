<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Files\GeneratedIcon;
use App\Files\SiteIcon;
use App\Files\SiteIconState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/** Public, like the shells that link these: a tab icon has to resolve for a guest. */
class SiteIconController extends Controller
{
    private const CACHE = 'public, max-age=86400';

    /** A tile is what the shells show until a raster lands, and `/favicon.ico` is asked for without a version. */
    private const CACHE_TILE = 'public, max-age=300';

    public function svg(SiteIcon $icon, GeneratedIcon $generated): Response
    {
        abort_unless($icon->state() === SiteIconState::Generated, 404);

        return $this->bytes($generated->svg(), 'image/svg+xml', self::CACHE);
    }

    public function ico(SiteIcon $icon, GeneratedIcon $generated): Response|RedirectResponse
    {
        return match ($icon->state()) {
            SiteIconState::Uploaded => redirect((string) brand_favicon_url()),
            SiteIconState::Generated => $this->bytes($generated->ico(), 'image/x-icon', $generated->hasRaster() ? self::CACHE : self::CACHE_TILE),
            // A bare 404, not the error page: browsers ask for this path on every page of a site
            // that links no icon.
            SiteIconState::None => response('', 404, ['Cache-Control' => self::CACHE]),
        };
    }

    public function png(int $size, GeneratedIcon $generated): Response
    {
        $raster = $generated->raster($size);

        return $raster === null
            ? $this->bytes($generated->tile($size), 'image/png', self::CACHE_TILE)
            : $this->bytes($raster, 'image/png', self::CACHE);
    }

    private function bytes(string $body, string $type, string $cache): Response
    {
        return response($body, 200, [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $cache,
        ]);
    }
}
