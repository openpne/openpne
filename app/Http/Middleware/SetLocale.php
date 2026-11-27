<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Member;
use App\Support\AdminRealm;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * The member's persisted locale outranks the session toggle because it is the durable choice
 * (OpenPNE 3 member_config[lang]); a guest keeps the session toggle. The admin realm reads only its
 * own session key, as OpenPNE 3 kept the admin culture apart from the member's in either direction.
 */
class SetLocale
{
    public const SUPPORTED_LOCALES = ['ja', 'en'];

    /** The panel's language, which neither the member toggle nor members.locale writes. */
    public const ADMIN_SESSION_KEY = 'admin_locale';

    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale(AdminRealm::matches($request)
            ? $this->resolveLocale($request, self::ADMIN_SESSION_KEY, memberAware: false)
            : $this->resolveLocale($request, 'locale', memberAware: true));

        return $next($request);
    }

    private function resolveLocale(Request $request, string $sessionKey, bool $memberAware): string
    {
        if ($memberAware) {
            $member = $request->user('member');
            if ($member instanceof Member && in_array($member->locale, self::SUPPORTED_LOCALES, strict: true)) {
                return $member->locale;
            }
        }

        $session = $request->session()->get($sessionKey);
        if (in_array($session, self::SUPPORTED_LOCALES, strict: true)) {
            return $session;
        }

        // Explicit membership check and fallback because getPreferredLanguage() answers 'en' from
        // Request::create()'s default Accept-Language or an unparseable header, while ja is the app default.
        $preferred = $request->getPreferredLanguage(self::SUPPORTED_LOCALES);
        if (in_array($preferred, self::SUPPORTED_LOCALES, strict: true)) {
            return $preferred;
        }

        return self::SUPPORTED_LOCALES[0];
    }
}
