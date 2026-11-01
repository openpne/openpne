<?php

declare(strict_types=1);

namespace App\Mcp\OAuth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Laravel\Passport\Client;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passport skips the consent screen for a client the user already holds a live token with, and
 * `prompt=login` ends the member session on a GET anyone can link to; here every connection chooses
 * who it speaks for on a screen that is never skipped, and a session ends only where the member ends it.
 */
class AuthorizeController extends AuthorizationController
{
    public function authorize(
        ServerRequestInterface $psrRequest,
        Request $request,
        ResponseInterface $psrResponse,
        AuthorizationViewResponse $viewResponse
    ): Response|AuthorizationViewResponse {
        $request->query->set('prompt', $request->string('prompt')->explode(' ')->reject(fn (string $p): bool => $p === 'login')->implode(' '));

        return parent::authorize($psrRequest, $request, $psrResponse, $viewResponse);
    }

    protected function hasGrantedScopes(Authenticatable $user, Client $client, array $scopes): bool
    {
        return false;
    }
}
