<?php

use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RemoveCookiesFromPublicFileResponses;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\UseAdminSessionStore;
use App\Support\ClassicErrorPage;
use App\Support\GuestLoginRedirect;
use App\Support\SecurityLog;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a reverse proxy, $request->ip()/scheme reflect the proxy, not the client, unless
        // the proxy is trusted — which collapses every per-IP rate limit and the HTTPS check.
        // TRUSTED_PROXIES is the proxy IP/CIDR list (or "*" to trust all forwarded headers); empty
        // trusts none. X-Forwarded-Host is deliberately NOT trusted — the real Host is validated by
        // trustHosts instead, keeping the host-poisoning surface closed.
        $proxies = trim((string) env('TRUSTED_PROXIES'));
        $middleware->trustProxies(
            at: $proxies === '' ? null : ($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies))),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Pin the trusted Host to exactly APP_URL — subdomains: false, so a wildcard-DNS or
        // attacker-controlled subdomain is not trusted either — so a forged Host cannot poison
        // generated URLs (notably the password-reset link). Enforced outside local/testing.
        $middleware->trustHosts(
            at: fn () => array_filter([
                ($host = parse_url((string) config('app.url'), PHP_URL_HOST)) ? '^'.preg_quote($host).'$' : null,
            ]),
            subdomains: false,
        );

        // Global, ahead of every group: it must pin the session store (cookie + table)
        // and default guard for the request's realm before anything resolves the
        // session driver — including the web group's StartSession, which also serves
        // the admin realm's Livewire endpoints.
        $middleware->prepend(UseAdminSessionStore::class);

        // A response that aborts inside the group unwinds through only the middleware it had already
        // entered, so a slot at the end of the group misses the 419, the guest redirect and the
        // implicit-binding 404 — all pages a member sees. SecurityHeaders sets static headers, so it
        // goes ahead of everything that can abort, as it does in the Filament panel stack (only the
        // cookie scrub, which reads the finished response, sits outside it); SetLocale needs the session, so it
        // goes right after StartSession/ShareErrorsFromSession and ahead of the first middleware
        // that can abort. PreventRequestForgery has to join the priority list to be that anchor.
        $middleware->web(prepend: [
            // Outermost, so it sees the response after EncryptCookies and
            // AddQueuedCookiesToResponse have attached the session cookies.
            RemoveCookiesFromPublicFileResponses::class,
            SecurityHeaders::class,
        ], append: [
            SetLocale::class,
            HandleInertiaRequests::class,
        ]);
        $middleware->appendToPriorityList(ShareErrorsFromSession::class, PreventRequestForgery::class);
        $middleware->prependToPriorityList(PreventRequestForgery::class, SetLocale::class);

        // Where a route lists the feature gate, it must run right after auth (a guest in an auth
        // group still meets the login redirect, so the toggle state never shows) but before
        // ThrottleRequests and SubstituteBindings: a disabled unit's request must not consume a
        // rate limiter, and must not reach a binding's missing() handler — /diary/listMember's
        // guest-login fallback would otherwise answer 302 where the spec says 404.
        $middleware->appendToPriorityList(AuthenticatesRequests::class, EnsureFeatureEnabled::class);

        // An already-authenticated member on /login or /register goes through the root so the
        // landing stays surface-aware; the framework default would pick the Modern /dashboard.
        $middleware->redirectUsersTo(fn () => route('home'));

        // OpenPNE 3 sent a guest to the login form with a notice rather than a bare form. The
        // callback runs only where the framework redirects a guest, so /login itself never flashes
        // it — the same exclusion OpenPNE 3 made for its homepage and login actions.
        $middleware->redirectGuestsTo(fn (): string => GuestLoginRedirect::target());
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Give the security log 429 observability (rate-limit tuning depends on it). A
        // ThrottleRequestsException is an HttpException, and the handler ignores *every*
        // HttpException by default (internalDontReport), so un-ignoring only the subclass does
        // not lift the parent's ignore — the parent must be un-ignored, then re-narrowed here.
        // ->stop() fires for every HttpException: 429s reach the security channel, and the rest
        // stay out of the default channel exactly as before (they were already ignored). The
        // limiter key is deliberately never logged — login keys embed the attempted email; ip and
        // user_agent come from SecurityLog's request auto-attach. See docs/internals/logging.md.
        $exceptions->stopIgnoring(HttpException::class);
        $exceptions->report(function (HttpException $e): void {
            if ($e instanceof ThrottleRequestsException) {
                SecurityLog::event('throttle.hit', [
                    'route' => request()->route()?->getName(),
                    'member_id' => request()->user()?->getKey(),
                ]);
            }
        })->stop();

        // Render the errors a member can walk into inside the Classic shell. A render callback
        // rather than resources/views/errors/4xx.blade.php overrides: those apply to every realm
        // and surface, and the choice here is per-request. Returning null leaves the framework's
        // own error response untouched. See App\Support\ClassicErrorPage.
        $exceptions->render(fn (HttpExceptionInterface $e, Request $request) => ClassicErrorPage::render($request, $e));
    })->create();

// Let the env file and storage directory be relocated to deployer-chosen paths
// (defaults to the in-project locations when unset). See docs/internals/runtime.md.
if ($path = getenv('OPENPNE_ENV_PATH')) {
    $app->useEnvironmentPath($path);
}
if ($path = getenv('LARAVEL_STORAGE_PATH')) {
    $app->useStoragePath($path);
}

return $app;
