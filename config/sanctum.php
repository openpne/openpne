<?php

/*
 * Personal access tokens only — the bearer credential an MCP client presents. The SPA cookie flow
 * Sanctum also ships is off (no stateful domains, no CSRF cookie route). Every key the app depends
 * on is stated here rather than left to the package's config merge, which is skipped once the
 * configuration is cached.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    'stateful' => [],

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    // Empty, not the package default ['web']. Sanctum consults every guard listed here BEFORE it
    // reads the bearer token, so any entry lets a browser session authenticate the token surface —
    // which a machine credential must not inherit. `web` is also a phantom here: config/auth.php
    // defines only `member` and `admin`, but the framework merges its own base auth.guards on top,
    // so `web` resolves to the `users` provider and App\Models\User, a class this app lacks.
    'guard' => [],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    |
    */

    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Routes
    |--------------------------------------------------------------------------
    |
    | Sanctum registers a /sanctum/csrf-cookie route for the SPA cookie flow.
    | This app authenticates with tokens only, so the route is not defined.
    |
    */

    'routes' => false,

];
