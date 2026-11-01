<?php

declare(strict_types=1);

namespace App\Features\AiAccount\Actions;

use App\Features\AiAccount\ActorLock;
use App\Features\AiAccount\Exceptions\AiAccountActionException;
use App\Features\AiAccount\Exceptions\AiAccountActionFailure;
use App\Features\AiAccount\TokenActorEligibility;
use App\Models\Member;

/**
 * Decides which member an OAuth grant speaks for, on locked rows, inside the caller's transaction:
 * the authorization code is persisted under the same lock (docs/internals/mcp.md, "Signing in from
 * a client").
 */
class BindConsentActor
{
    /**
     * @param  'self'|int  $actor  the viewer, or the id of an AI account they own
     *
     * @throws AiAccountActionException
     */
    public function __invoke(Member $viewer, string|int $actor): Member
    {
        $locked = ActorLock::acquire($actor === 'self' ? (int) $viewer->getKey() : $actor);

        if ($locked === null) {
            throw new AiAccountActionException(AiAccountActionFailure::MemberGone);
        }

        if ($actor !== 'self' && (int) $locked->owner_member_id !== (int) $viewer->getKey()) {
            throw new AiAccountActionException(AiAccountActionFailure::NotOwned);
        }

        if (! TokenActorEligibility::permits($locked)) {
            throw new AiAccountActionException(AiAccountActionFailure::ActorFrozen);
        }

        return $locked;
    }
}
