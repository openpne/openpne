<?php

declare(strict_types=1);

namespace App\Mcp\OAuth;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client;
use Laravel\Passport\Http\Controllers\AuthorizationController;

/**
 * Passport skips the consent screen for a client the user already holds a live token with; here
 * every connection chooses who it speaks for, so the screen is never skipped.
 */
class AuthorizeController extends AuthorizationController
{
    protected function hasGrantedScopes(Authenticatable $user, Client $client, array $scopes): bool
    {
        return false;
    }
}
