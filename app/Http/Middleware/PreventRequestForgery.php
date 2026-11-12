<?php

namespace App\Http\Middleware;

use App\Session\MemberSessionLifetime;
use App\Support\CookiePrefix;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as BasePreventRequestForgery;
use Symfony\Component\HttpFoundation\Cookie;

class PreventRequestForgery extends BasePreventRequestForgery
{
    public static function cookieName(): string
    {
        return CookiePrefix::apply('XSRF-TOKEN');
    }

    /** Lives as long as the session cookie it accompanies (docs/internals/sessions.md, "Lifetimes"). */
    protected function newCookie($request, $config)
    {
        $minutes = MemberSessionLifetime::cookieMinutes($request);
        $cookie = parent::newCookie($request, $minutes === null ? $config : ['lifetime' => $minutes] + $config);

        return new Cookie(
            self::cookieName(),
            $cookie->getValue(),
            $cookie->getExpiresTime(),
            $cookie->getPath(),
            $cookie->getDomain(),
            $cookie->isSecure(),
            $cookie->isHttpOnly(),
            $cookie->isRaw(),
            $cookie->getSameSite(),
            $cookie->isPartitioned(),
        );
    }
}
