<?php

declare(strict_types=1);

namespace App\Mcp\OAuth;

use Illuminate\Support\Facades\DB;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * One transaction around the exchange, so the member lock LockedAccessTokenRepository takes holds
 * until the new rows are committed and a refresh that lost the single-use race leaves nothing behind.
 */
class TokenController extends AccessTokenController
{
    public function issueToken(ServerRequestInterface $psrRequest, ResponseInterface $psrResponse): Response
    {
        return DB::transaction(fn (): Response => parent::issueToken($psrRequest, $psrResponse));
    }
}
