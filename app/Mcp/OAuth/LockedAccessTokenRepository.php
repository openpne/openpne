<?php

declare(strict_types=1);

namespace App\Mcp\OAuth;

use App\Features\AiAccount\ActorLock;
use App\Features\AiAccount\TokenActorEligibility;
use App\Models\Member;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * Every write here first locks the member's row (owner first, as every sweep does) and re-asks the
 * freeze question there, so a grant racing a ban is refused rather than outliving the sweep and a
 * refresh cannot deadlock with it. Requires the caller's transaction (TokenController).
 */
class LockedAccessTokenRepository extends AccessTokenRepository
{
    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $userId = $accessTokenEntity->getUserIdentifier();

        if ($userId !== null && ! $this->lockEligible((int) $userId)) {
            throw OAuthServerException::accessDenied('The account can no longer be used.');
        }

        parent::persistNewAccessToken($accessTokenEntity);
    }

    public function revokeAccessToken(string $tokenId): void
    {
        $userId = Passport::token()->newQuery()->whereKey($tokenId)->value('user_id');

        if ($userId !== null && ! $this->lockEligible((int) $userId)) {
            throw OAuthServerException::accessDenied('The account can no longer be used.');
        }

        parent::revokeAccessToken($tokenId);
    }

    private function lockEligible(int $memberId): bool
    {
        $actor = ActorLock::acquire($memberId);

        return $actor instanceof Member && TokenActorEligibility::permits($actor);
    }
}
