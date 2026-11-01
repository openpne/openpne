<?php

declare(strict_types=1);

namespace App\Mcp\OAuth;

use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * The same compare-and-set as the refresh token: league checks `revoked` before issuing and revokes
 * after, so two exchanges of one code would otherwise both succeed. A client's first code marks it
 * used, which is what keeps it out of the prune once its rows are gone.
 */
class SingleUseAuthCodeRepository extends AuthCodeRepository
{
    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        parent::persistNewAuthCode($authCodeEntity);

        Passport::client()->newQuery()
            ->whereKey($authCodeEntity->getClient()->getIdentifier())
            ->whereNull('used_at')
            ->update(['used_at' => now()]);
    }

    public function revokeAuthCode(string $codeId): void
    {
        $consumed = Passport::authCode()->newQuery()
            ->whereKey($codeId)
            ->where('revoked', false)
            ->update(['revoked' => true]);

        if ($consumed === 0) {
            throw OAuthServerException::invalidGrant('Authorization code has been revoked');
        }
    }
}
