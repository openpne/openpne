<?php

declare(strict_types=1);

namespace App\Features\AiAccount\Actions;

use App\Features\AiAccount\Exceptions\AiAccountActionException;
use App\Features\AiAccount\Exceptions\AiAccountActionFailure;
use App\Features\AiAccount\TokenActorEligibility;
use App\Mcp\McpAbilities;
use App\Models\Member;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;

/**
 * Mint the personal access token an MCP client presents as $member.
 *
 * Who may ask is the caller's question — server access for the CLI, ownership for the owner's
 * settings screen — so no authorization is decided here. What is decided here is the part the two
 * callers must not answer differently: the abilities are always named (never Sanctum's wildcard,
 * which passes every gate), and the decision to mint is taken on rows locked in this feature's
 * order — the owner's first, then the account's — with eligibility re-read from those locked rows.
 * Reading it from the caller's snapshot instead would let a mint hand back the reach a freeze
 * committing beside it has just taken away.
 *
 * The transaction ends when this returns, so a caller that logs or prints afterwards is reporting a
 * token that exists. The audit line is the caller's to write: `via` is a fact about which trust
 * boundary was crossed, which only the caller knows.
 */
class IssueMcpToken
{
    /** @throws AiAccountActionException */
    public function __invoke(Member $member, bool $readOnly = false): NewAccessToken
    {
        $abilities = $readOnly ? [McpAbilities::READ] : [McpAbilities::READ, McpAbilities::WRITE];

        return DB::transaction(function () use ($member, $abilities): NewAccessToken {
            $actor = $this->lockActor($member);

            if (! TokenActorEligibility::permits($actor)) {
                throw new AiAccountActionException(AiAccountActionFailure::ActorFrozen);
            }

            return $actor->createToken(McpAbilities::TOKEN_NAME, $abilities);
        });
    }

    /**
     * The member row locked behind its owner's, when it has one.
     *
     * `owner_member_id` is immutable, so the caller's snapshot is enough to decide the lock order;
     * every value that is then judged comes from the locked rows. The owner is attached to the
     * relation rather than left to lazy-load, so the eligibility predicate reads the row this
     * transaction locked instead of issuing a fresh unlocked query for it.
     *
     * @throws AiAccountActionException
     */
    private function lockActor(Member $member): Member
    {
        $owner = null;

        if ($member->owner_member_id !== null) {
            $owner = Member::whereKey($member->owner_member_id)->lockForUpdate()->first();

            if ($owner === null) {
                throw new AiAccountActionException(AiAccountActionFailure::MemberGone);
            }
        }

        $actor = Member::whereKey($member->getKey())->lockForUpdate()->first();

        if ($actor === null) {
            throw new AiAccountActionException(AiAccountActionFailure::MemberGone);
        }

        if ($owner !== null) {
            $actor->setRelation('owner', $owner);
        }

        return $actor;
    }
}
