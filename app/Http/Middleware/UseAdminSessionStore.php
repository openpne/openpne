<?php

namespace App\Http\Middleware;

use App\Support\AdminRealm;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pins the session store and the default auth guard to the realm of the request path, so the two
 * realms keep separate sessions (docs/internals/sessions.md). Global middleware, not the panel
 * stack: Livewire requests run under the `web` group, and Livewire's persistent middleware is
 * re-applied only after StartSession has already resolved the store.
 */
class UseAdminSessionStore
{
    private readonly string $memberCookie;

    private readonly string $memberGuard;

    // Bound as a container singleton so the member-realm base values are captured once per process,
    // before any request's pin mutates them.
    public function __construct()
    {
        $this->memberCookie = (string) config('session.cookie');
        $this->memberGuard = (string) config('auth.defaults.guard');
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Pin both branches on every request: config() and shouldUse() mutations
        // outlive the request wherever one process serves several (tests, any
        // future long-lived runtime), so the member branch must restore, not assume.
        $admin = AdminRealm::matches($request);

        config([
            'session.cookie' => $this->cookieName($admin ? config('session.admin_cookie') : $this->memberCookie),
            'session.table' => $admin ? config('session.admin_table') : config('session.member_table'),
        ]);
        Auth::shouldUse($admin ? 'admin' : $this->memberGuard);

        $response = $next($request);

        if ($admin) {
            // One global cookie name would let an admin response overwrite the member realm's token;
            // removeCookie matches an exact name/path/domain triple, which must mirror how
            // PreventRequestForgery queued it.
            $response->headers->removeCookie(
                'XSRF-TOKEN',
                config('session.path', '/'),
                config('session.domain'),
            );
        }

        return $response;
    }

    /**
     * A browser rejects a `__Secure-` cookie that is not Secure over HTTPS, so the prefix is added
     * exactly when `session.secure` is on. An already-prefixed base (an operator-set name, or the pin
     * re-deriving in a long-lived process) is left untouched, since `__Secure-__Host-…` would demote a
     * `__Host-` name.
     */
    private function cookieName(string $base): string
    {
        if (! config('session.secure') || str_starts_with($base, '__Secure-') || str_starts_with($base, '__Host-')) {
            return $base;
        }

        return '__Secure-'.$base;
    }
}
