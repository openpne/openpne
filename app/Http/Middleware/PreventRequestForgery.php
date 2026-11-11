<?php

namespace App\Http\Middleware;

use App\Support\CookiePrefix;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as BasePreventRequestForgery;
use Symfony\Component\HttpFoundation\Cookie;

class PreventRequestForgery extends BasePreventRequestForgery
{
    public static function cookieName(): string
    {
        return CookiePrefix::apply('XSRF-TOKEN');
    }

    protected function newCookie($request, $config)
    {
        $cookie = parent::newCookie($request, $config);

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
