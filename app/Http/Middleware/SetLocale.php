<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the request locale in priority order:
 *   1. session `locale` value (set by POST /locale during the current session)
 *   2. Accept-Language header negotiated against SUPPORTED_LOCALES
 *
 * Member-side persistence (e.g. a `member_config[lang]` analogue) is intentionally
 * not wired yet — add a third step here once a member preference store exists.
 */
class SetLocale
{
    public const SUPPORTED_LOCALES = ['ja', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolveLocale($request));

        return $next($request);
    }

    private function resolveLocale(Request $request): string
    {
        $session = $request->session()->get('locale');
        if (in_array($session, self::SUPPORTED_LOCALES, strict: true)) {
            return $session;
        }

        // Accept-Language negotiation. Explicit membership check + fallback to the
        // first supported locale because Symfony's getPreferredLanguage() can be
        // shadowed by Request::create() test defaults (HTTP_ACCEPT_LANGUAGE
        // 'en-us,en;q=0.5') and by unparseable headers — both would otherwise
        // return 'en' even though we declare ja as the app default.
        $preferred = $request->getPreferredLanguage(self::SUPPORTED_LOCALES);
        if (in_array($preferred, self::SUPPORTED_LOCALES, strict: true)) {
            return $preferred;
        }

        return self::SUPPORTED_LOCALES[0];
    }
}
