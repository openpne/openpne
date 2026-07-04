<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Give the admin realm its own session store, separate from the member realm.
 * ("Realm" is the member/admin split; "surface" is reserved for Classic/Modern.)
 * With separate stores an operator stays signed in to both in one browser,
 * `url.intended` cannot leak a redirect across realms, and either side's logout
 * (`session()->invalidate()`) destroys only its own store.
 *
 * This must be GLOBAL middleware, keyed by request path: Livewire endpoints — which
 * carry every Filament interaction after the initial page load — run under the `web`
 * group, not the panel middleware stack, and Livewire's persistent middleware is
 * re-applied only after StartSession has already resolved the store.
 *
 * The default auth guard is pinned to the same realm so the database session
 * handler stamps `user_id` from the matching guard even on admin-realm requests
 * where Filament's Authenticate never runs (login-screen Livewire updates, file
 * uploads, the locale switch). Without the pin those writes null the column and the
 * row would escape a future purge-by-admin-id.
 */
class UseAdminSessionStore
{
    private readonly string $memberCookie;

    private readonly string $memberGuard;

    // Bound as a container singleton: the member-realm base values are captured
    // once per process, before any request's pin mutates them. The table pin needs
    // no capture — session.member_table / session.admin_table are stable keys.
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
        $admin = $this->isAdminRealm($request);

        config([
            'session.cookie' => $this->cookieName($admin ? config('session.admin_cookie') : $this->memberCookie),
            'session.table' => $admin ? config('session.admin_table') : config('session.member_table'),
        ]);
        Auth::shouldUse($admin ? 'admin' : $this->memberGuard);

        $response = $next($request);

        if ($admin) {
            // The XSRF-TOKEN cookie is one global name, so whichever realm responds
            // last would overwrite the other's token and 419 the member realm's next
            // Inertia/axios POST. Nothing on the admin realm reads it (Livewire sends
            // its page-embedded data-csrf token) — drop it from admin responses instead.
            // removeCookie only matches an exact name/path/domain triple, which must
            // mirror how PreventRequestForgery queued it.
            $response->headers->removeCookie(
                'XSRF-TOKEN',
                config('session.path', '/'),
                config('session.domain'),
            );
        }

        return $response;
    }

    /**
     * The `__Secure-` prefix ties the cookie to HTTPS: a browser accepts a `__Secure-`
     * cookie only when it carries the Secure attribute over TLS, and rejects it outright
     * otherwise. So add the prefix exactly when the session cookie is already Secure
     * (`session.secure` — set explicitly or by force_https), never on a plain-HTTP dev
     * host where it would silently break login. Leave an already-prefixed base untouched
     * — an operator-set SESSION_COOKIE, or the pin re-deriving in a long-lived process —
     * including a stricter `__Host-` name, which `__Secure-__Host-…` would demote.
     */
    private function cookieName(string $base): string
    {
        if (! config('session.secure') || str_starts_with($base, '__Secure-') || str_starts_with($base, '__Host-')) {
            return $base;
        }

        return '__Secure-'.$base;
    }

    private function isAdminRealm(Request $request): bool
    {
        // 'admin' mirrors AdminPanelProvider->path('admin'); the Livewire and Filament
        // prefixes come from the same resolvers those packages register routes with.
        // All three are pinned against the real routes by AdminSessionStoreTest.
        // Livewire endpoints belong to the admin realm: nothing outside app/Filament
        // renders Livewire (architecture-test enforced), and the Filament system routes
        // (export/import downloads) authenticate against the admin guard.
        $livewire = ltrim(EndpointResolver::prefix(), '/');
        $filament = (string) config('filament.system_route_prefix', 'filament');

        return $request->is(
            'admin', 'admin/*',
            $livewire, $livewire.'/*',
            $filament, $filament.'/*',
        );
    }
}
