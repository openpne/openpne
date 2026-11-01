<?php

declare(strict_types=1);

namespace App\Mcp\OAuth;

use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * league reads `revoked` and then updates unconditionally, so two concurrent refreshes of one token
 * both succeed; the compare-and-set here lets exactly one through and the other's transaction rolls
 * back the rows it already wrote.
 */
class SingleUseRefreshTokenRepository extends RefreshTokenRepository
{
    public function revokeRefreshToken(string $tokenId): void
    {
        $consumed = Passport::refreshToken()->newQuery()
            ->whereKey($tokenId)
            ->where('revoked', false)
            ->update(['revoked' => true]);

        if ($consumed === 0) {
            throw OAuthServerException::invalidRefreshToken('Token has been revoked');
        }
    }
}
