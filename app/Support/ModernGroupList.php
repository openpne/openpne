<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What a row of the member's own joined list opens under Modern, in the list and in the sidebar alike
 * (docs/internals/group-talk.md, "The joined-group list is a room list").
 */
enum ModernGroupList: string
{
    case Talk = 'talk';

    case Group = 'group';

    /** Label as a translation key, like Look: the consumer translates in the reader's locale. */
    public function label(): string
    {
        return match ($this) {
            self::Talk => 'Talk',
            self::Group => 'The %community% page',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Talk => 'Each %community% opens its talk, the way a chat app opens a room.',
            self::Group => 'Each %community% opens its page, where its %topics%, events and talk are listed, the way OpenPNE 3 opened a community.',
        };
    }
}
