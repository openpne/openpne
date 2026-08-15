<?php

declare(strict_types=1);

namespace App\Features\AiAccount\Exceptions;

enum AiAccountActionFailure: string
{
    /** The site does not offer AI accounts. Creation only — the manage paths never raise this. */
    case Disabled = 'disabled';

    /** The would-be owner is banned, so nothing may be minted in their name. */
    case OwnerFrozen = 'owner_frozen';

    /** An AI account cannot own one: the ownership graph is one level deep by construction. */
    case OwnerIsAiAccount = 'owner_is_ai_account';

    /** The owner already holds as many as the site allows. */
    case LimitReached = 'limit_reached';

    /** The named account is not one this member owns. */
    case NotOwned = 'not_owned';

    /** Token mint only: the account, or the owner behind it, is banned. */
    case ActorFrozen = 'actor_frozen';

    /** The row was withdrawn between the caller reading it and the write locking it. */
    case MemberGone = 'member_gone';
}
