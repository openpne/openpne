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
    /** Kept rather than `login` struck out: Passport passes each value's index to `trim()` as the characters to strip, so `0login` reaches it as `login`. */
    private const PROMPTS = ['none', 'consent', 'select_account'];

    public function authorize(
        ServerRequestInterface $psrRequest,
        Request $request,
        ResponseInterface $psrResponse,
        AuthorizationViewResponse $viewResponse
    ): Response|AuthorizationViewResponse {
        $request->merge(['prompt' => $request->string('prompt')->explode(' ')->intersect(self::PROMPTS)->implode(' ')]);

        return parent::authorize($psrRequest, $request, $psrResponse, $viewResponse);
    }

    protected function hasGrantedScopes(Authenticatable $user, Client $client, array $scopes): bool
    {
        return false;
    }
}
