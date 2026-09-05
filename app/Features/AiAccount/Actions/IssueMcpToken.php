<?php

declare(strict_types=1);

namespace App\Features\AiAccount\Actions;

use App\Features\AiAccount\Exceptions\AiAccountActionException;
use App\Features\AiAccount\Exceptions\AiAccountActionFailure;
use App\Features\AiAccount\MemberSelector;
use App\Features\AiAccount\TokenActorEligibility;
use App\Mcp\McpAbilities;
use App\Models\Member;
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
     * re-asked inside this transaction rather than trusted from a lookup that ran before it.
     *
     * @throws AiAccountActionException
     */
    public function __invoke(Member|MemberSelector $member, bool $readOnly = false): NewAccessToken
    {
        $selector = $member instanceof Member ? MemberSelector::of($member) : $member;
        $abilities = $readOnly ? [McpAbilities::READ] : [McpAbilities::READ, McpAbilities::WRITE];

        return DB::transaction(function () use ($selector, $abilities): NewAccessToken {
            $actor = $this->lockActor($selector);

            if (! TokenActorEligibility::permits($actor)) {
                throw new AiAccountActionException(AiAccountActionFailure::ActorFrozen);
            }

            return $actor->createToken(McpAbilities::TOKEN_NAME, $abilities);
        });
    }

    /**
     * The owner's row is locked before the account's, and `owner_member_id` is immutable, so the
     * caller's snapshot decides that order. Everything then judged comes from the locked rows,
     * including whether the selector still names this one.
     *
     * @throws AiAccountActionException
     */
    private function lockActor(MemberSelector $selector): Member
    {
        $member = $selector->member();
        $owner = null;

        if ($member->owner_member_id !== null) {
            $owner = Member::whereKey($member->owner_member_id)->lockForUpdate()->first();

            if ($owner === null) {
                throw new AiAccountActionException(AiAccountActionFailure::MemberGone);
            }
        }

        $actor = Member::whereKey($member->getKey())->lockForUpdate()->first();

        if ($actor === null || ! $selector->names($actor)) {
            throw new AiAccountActionException(AiAccountActionFailure::MemberGone);
        }

        if ($owner !== null) {
            // So the eligibility read uses the row this transaction locked, not a fresh unlocked one.
            $actor->setRelation('owner', $owner);
        }

        return $actor;
    }
}
