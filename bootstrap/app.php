<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetLocale::class,
            HandleInertiaRequests::class,
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
