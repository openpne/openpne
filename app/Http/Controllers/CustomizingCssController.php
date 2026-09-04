<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A standalone stylesheet at OpenPNE 3's path, so @charset / @import and a migrated site's relative
 * url(...) keep resolving. Served from the DB with a content ETag, never written to disk, so no
 * storage:link is needed.
 */
class CustomizingCssController extends Controller
{
    public function show(Request $request, SnsSettingService $settings): Response
    {
        $css = (string) $settings->get(SnsSettingKey::CustomCss);

        $response = response($css, Response::HTTP_OK, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
        $response->setEtag(hash('xxh128', $css));

        // Drop the body to a 304 when the operator-edited bytes are unchanged.
        $response->isNotModified($request);

        return $response;
    }
}
