<?php

declare(strict_types=1);

namespace App\Features\AiAccount\Actions;

use App\Auth\SessionCredential;
use App\Features\AiAccount\ActorLock;
use App\Features\AiAccount\Exceptions\AiAccountActionException;
use App\Features\AiAccount\Exceptions\AiAccountActionFailure;
use App\Features\AiAccount\MemberSelector;
use App\Features\AiAccount\TokenActorEligibility;
use App\Mcp\McpAbilities;
use App\Models\Member;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;

/**
 * No authorization is decided here, but the abilities are always named — never Sanctum's wildcard,
 * which passes every gate. The transaction ends when this returns, so a caller that logs or prints
 * afterwards is reporting a token that exists.
 */
class IssueMcpToken
{
    /**
     * A caller that found the member itself passes a {@see MemberSelector}, so how it was named is
     * re-asked inside this transaction rather than trusted from a lookup that ran before it. A
     * caller acting on a signed-in session passes it, and the mint is refused when that session's
     * password is no longer the owner's on the locked row.
     *
     * @throws AiAccountActionException
     * @throws AuthenticationException
     */
    public function __invoke(Member|MemberSelector $member, bool $readOnly = false, ?Session $session = null): NewAccessToken
    {
        $selector = $member instanceof Member ? MemberSelector::of($member) : $member;
        $abilities = $readOnly ? [McpAbilities::READ] : [McpAbilities::READ, McpAbilities::WRITE];

        return DB::transaction(function () use ($selector, $abilities, $session): NewAccessToken {
            $actor = $this->lockActor($selector);

            if (! TokenActorEligibility::permits($actor)) {
                throw new AiAccountActionException(AiAccountActionFailure::ActorFrozen);
            }

            if ($session !== null && ! SessionCredential::stillHolds($session, $actor->owner ?? $actor)) {
                throw new AuthenticationException;
            }

            return $actor->createToken(McpAbilities::TOKEN_NAME, $abilities);
        });
    }

    /**
     * Everything judged comes from the locked rows, including whether the selector still names
     * this one.
     *
     * @throws AiAccountActionException
     */
    private function lockActor(MemberSelector $selector): Member
    {
        $actor = ActorLock::acquire((int) $selector->member()->getKey());

        if ($actor === null || ! $selector->names($actor)) {
            throw new AiAccountActionException(AiAccountActionFailure::MemberGone);
        }

        return $actor;
    }
}
