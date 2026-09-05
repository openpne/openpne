<?php

use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureTimelinePostingEnabled;
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
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // X-Forwarded-Host is deliberately not trusted: the Host is validated by trustHosts instead
        // (docs/internals/runtime.md).
        $proxies = trim((string) env('TRUSTED_PROXIES'));
        $middleware->trustProxies(
            at: $proxies === '' ? null : ($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies))),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Exactly APP_URL's host, subdomains refused too, so a forged Host cannot poison generated URLs
        // such as the password-reset link.
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

        // An abort unwinds only through middleware already entered, so SecurityHeaders and SetLocale
        // must sit ahead of the first middleware that can abort (docs/internals/security.md).
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

        // The feature gate runs after auth, so a guest never learns the toggle state, and before
        // ThrottleRequests and SubstituteBindings, so a disabled unit consumes no limiter and reaches no
        // missing() handler.
        $middleware->appendToPriorityList(AuthenticatesRequests::class, EnsureFeatureEnabled::class);
        $middleware->appendToPriorityList(EnsureFeatureEnabled::class, EnsureTimelinePostingEnabled::class);

        // Sanctum ships its ability checks unaliased; `ability` is the any-of check, which for a single
        // ability is also all-of.
        $middleware->alias(['ability' => CheckForAnyAbility::class]);

        // An already-authenticated member on /login or /register goes through the root so the
        // landing stays surface-aware; the framework default would pick the Modern /dashboard.
        $middleware->redirectUsersTo(fn () => route('home'));

        // Null for the MCP endpoint, which answers 401 with no body: a bearer client has no login form,
        // and a target here would flash a notice into a session that realm never starts.
        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('mcp') ? null : GuestLoginRedirect::target(),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The handler ignores every HttpException by default, and un-ignoring only the subclass would
        // not lift that, so the parent is un-ignored and ->stop() keeps the rest out of the default channel.
        $exceptions->stopIgnoring(HttpException::class);
        $exceptions->report(function (HttpException $e): void {
            if ($e instanceof ThrottleRequestsException) {
                // The limiter key is never logged: login keys embed the attempted email.
                SecurityLog::event('throttle.hit', [
                    'route' => request()->route()?->getName(),
                    'member_id' => request()->user()?->getKey(),
                ]);
            }
        })->stop();

        // A render callback rather than errors/4xx.blade.php overrides, which would apply to every
        // realm and surface.
        $exceptions->render(fn (HttpExceptionInterface $e, Request $request) => ClassicErrorPage::render($request, $e));
    })->create();

// Deployer-chosen env and storage paths; unset means the in-project locations (docs/internals/runtime.md).
if ($path = getenv('OPENPNE_ENV_PATH')) {
    $app->useEnvironmentPath($path);
}
if ($path = getenv('LARAVEL_STORAGE_PATH')) {
    $app->useStoragePath($path);
}

return $app;
