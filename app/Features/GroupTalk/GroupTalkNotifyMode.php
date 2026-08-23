<?php

declare(strict_types=1);

namespace App\Features\GroupTalk;

/**
 * How much of a talk a site notifies about by default: only the messages that name you, or every
 * message. It is the site's answer, not a member's — a member's own catalog row overrides it
 * (docs/internals/notifications.md), and muting a room takes that room out either way.
 *
 * Stored in SnsSettingKey::GroupTalkNotifyDefault; read through GroupTalkNotifyDefault.
 */
enum GroupTalkNotifyMode: string
{
    case Mentions = 'mentions';

    case All = 'all';

    /** Label as a translation key, like Look: the consumer translates in the reader's locale. */
    public function label(): string
    {
        return match ($this) {
            self::Mentions => 'Only mentions',
            self::All => 'Every message',
        };
    }

    /** One-line description for the picker, as a translation key. */
    public function description(): string
    {
        return match ($this) {
            self::Mentions => 'Members are notified only when a message names them.',
            self::All => 'Members are notified of every message, which suits a small site. A member can still silence a room or turn the notification off.',
        };
    }
}
