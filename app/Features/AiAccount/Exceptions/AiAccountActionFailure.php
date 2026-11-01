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

    public function message(): string
    {
        return match ($this) {
            self::Disabled => __('This site is not offering AI accounts right now.'),
            self::OwnerFrozen, self::OwnerIsAiAccount => __('You cannot create an AI account.'),
            self::LimitReached => __('You already have as many AI accounts as this site allows.'),
            self::NotOwned, self::MemberGone => __('That is not one of your AI accounts.'),
            self::ActorFrozen => __('This AI account cannot be given a token right now.'),
        };
    }
}
