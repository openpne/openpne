<?php

declare(strict_types=1);

namespace App\Features\AiAccount\Exceptions;

enum AiAccountActionFailure: string
{
    case Disabled = 'disabled';

    case OwnerFrozen = 'owner_frozen';

    case OwnerIsAiAccount = 'owner_is_ai_account';

    case LimitReached = 'limit_reached';

    case NotOwned = 'not_owned';

    /** The account, or the owner behind it, is banned. */
    case ActorFrozen = 'actor_frozen';

    /** The row was withdrawn between the caller reading it and the write locking it. */
    case MemberGone = 'member_gone';
}
