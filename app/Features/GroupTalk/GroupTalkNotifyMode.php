<?php

declare(strict_types=1);

namespace App\Features\GroupTalk;

/**
 * The site's answer, which a member's own catalog row overrides (docs/internals/notifications.md,
 * "The per-member catalog"). `label()` and `description()` return translation keys, translated in
 * the reader's locale.
 */
enum GroupTalkNotifyMode: string
{
    case Mentions = 'mentions';

    case All = 'all';

    public function label(): string
    {
        return match ($this) {
            self::Mentions => 'Only mentions',
            self::All => 'Every message',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Mentions => 'Members are notified only when a message names them.',
            self::All => 'Members are notified of every message, which suits a small site. A member can still silence a room or turn the notification off.',
        };
    }
}
