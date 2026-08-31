<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Routing\Route;

/**
 * The routes that answer with stored bytes rather than a page — an image, an attachment, a banner.
 *
 * Two middleware need the same list and must not keep two: a delivery response is never somewhere to
 * send a visitor back to (App\Http\Middleware\StartSession), and one that declares itself publicly
 * cacheable must leave without the session cookies (RemoveCookiesFromPublicFileResponses).
 *
 * Named rather than sniffed so a new delivery route is an explicit decision; FileDeliveryRoutesTest
 * pins every name to a registered GET route.
 */
final class FileDeliveryRoutes
{
    public const NAMES = [
        'file.show',
        'image.show',
        'banner.image',
        'file.public',
        'linkCard.image',
        'admin.file.raw',
    ];

    public static function matches(?Route $route): bool
    {
        return in_array($route?->getName(), self::NAMES, true);
    }
}
