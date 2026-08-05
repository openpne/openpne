<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithSurface;
use App\Services\SnsSettingService;
use App\Support\MarkdownText;
use App\Support\SnsSettingKey;
use App\Support\SurfaceResolver;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The two site policy pages (OpenPNE 3 default/userAgreement and default/privacyPolicy). Public:
 * someone deciding whether to join has to be able to read them, and OpenPNE 3 served them from a
 * module with `is_secure: false`.
 */
class PolicyController extends Controller
{
    use RespondsWithSurface;

    public function terms(Request $request): View|InertiaResponse
    {
        return $this->show($request, SnsSettingKey::UserAgreement, 'userAgreement', 'terms');
    }

    public function privacy(Request $request): View|InertiaResponse
    {
        return $this->show($request, SnsSettingKey::PrivacyPolicy, 'privacyPolicy', 'privacy');
    }

    /**
     * @param  string  $boxId  the OpenPNE 3 `op_include_box` id the skin and site CSS target
     * @param  string  $kind  which page this is, for the Modern chrome's heading (member-chrome.ts)
     */
    private function show(Request $request, SnsSettingKey $key, string $boxId, string $kind): View|InertiaResponse
    {
        $body = (string) app(SnsSettingService::class)->get($key);
        $title = $key->label();

        return $this->respondWith($request, 'policy', [
            // Always insecure_page, whatever the viewer's login state: OpenPNE 3 keyed the class off
            // the page (`isSecurePage`), and these pages are in a module it served to anyone.
            SurfaceResolver::CLASSIC => fn () => view('policy.show', [
                'title' => $title,
                'boxId' => $boxId,
                'body' => $body,
            ])->with('pageClass', 'insecure_page'),
            SurfaceResolver::MODERN => fn () => Inertia::render('policy/show', [
                'kind' => $kind,
                'body' => $body === '' ? null : $body,
                'bodyHtml' => $body === '' ? null : MarkdownText::render($body)->toHtml(),
            ]),
        ]);
    }
}
