<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the admin custom CSS (App\Support\SnsSettingKey::CustomCss) as a standalone text/css
 * document, the way OpenPNE 3 did (<link> to /cache/css/customizing.css). Linking it rather than
 * inlining a <style> block preserves stylesheet semantics: @charset / @import are honored, and
 * relative url(...) resolve against this URL's directory — kept at OpenPNE 3's path so a migrated
 * site's relative asset references still resolve. Served dynamically from the DB with a content ETag
 * (no cache file written), so a fleet host needs no storage:link.
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
