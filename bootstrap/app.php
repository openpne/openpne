<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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

        $middleware->web(append: [
            SetLocale::class,
            HandleInertiaRequests::class,
            SecurityHeaders::class,
        ]);

        // An already-authenticated member on /login or /register goes through the root so the
        // landing stays surface-aware; the framework default would pick the Modern /dashboard.
        $middleware->redirectUsersTo(fn () => route('home'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
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
