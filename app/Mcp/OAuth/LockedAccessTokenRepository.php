<?php

declare(strict_types=1);

namespace App\Mcp\OAuth;

use App\Features\AiAccount\ActorLock;
use App\Features\AiAccount\TokenActorEligibility;
use Laravel\Passport\Bridge\AccessTokenRepository;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * An access token is persisted only on the member's locked row, re-asking the freeze question there,
 * so a grant racing a ban or a withdrawal is refused rather than outliving the sweep. Requires the
 * caller's transaction (TokenController).
 */
class LockedAccessTokenRepository extends AccessTokenRepository
{
    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $userId = $accessTokenEntity->getUserIdentifier();

        if ($userId !== null) {
            $actor = ActorLock::acquire((int) $userId);

            if ($actor === null || ! TokenActorEligibility::permits($actor)) {
                throw OAuthServerException::accessDenied('The account can no longer be used.');
            }
        }

        parent::persistNewAccessToken($accessTokenEntity);
    }
}
