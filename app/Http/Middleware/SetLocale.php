<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Member;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the request locale in priority order:
 *   1. (member scope only) the authenticated member's persisted `members.locale`
 *   2. session `locale` value (set by POST /locale during the current session)
 *   3. Accept-Language header negotiated against SUPPORTED_LOCALES
 *
 * The member preference outranks the session because it is the member's durable choice
 * (OpenPNE 3 member_config[lang]); a guest session toggle remains the guest mechanism.
 *
 * Scope: the `web` group registers this as member-aware (step 1 active). The Filament admin
 * panel registers it with the `:session` scope so an admin page never picks up a co-logged-in
 * member's locale — admins follow session/Accept-Language only.
 */
class SetLocale
{
    public const SUPPORTED_LOCALES = ['ja', 'en'];

    public function handle(Request $request, Closure $next, string $scope = 'member'): Response
    {
        App::setLocale($this->resolveLocale($request, memberAware: $scope === 'member'));

        return $next($request);
    }

    private function resolveLocale(Request $request, bool $memberAware): string
    {
        if ($memberAware) {
            $member = $request->user('member');
            if ($member instanceof Member && in_array($member->locale, self::SUPPORTED_LOCALES, strict: true)) {
                return $member->locale;
            }
        }

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
