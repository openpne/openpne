<?php

namespace App\Features\Timeline;

use App\Support\Visibility;
use Illuminate\Validation\Rules\Enum;

/**
 * The audiences a member may choose when posting. Single source for the form options and the
 * request validation rule so the two cannot drift: both honour the openpne.timeline.allow_web_public
 * gate (OpenPNE 3 op_activity_is_open). The form default is Members (OpenPNE 3 public_flag SNS).
 */
final class TimelineVisibility
{
    /** @return list<Visibility> */
    public static function options(): array
    {
        $webPublic = self::allowsWebPublic() ? [Visibility::Open] : [];

        return [...$webPublic, Visibility::Members, Visibility::Friends, Visibility::Private];
    }

    /** Validation rule restricting visibility to the selectable audiences. */
    public static function rule(): Enum
    {
        $rule = new Enum(Visibility::class);

        return self::allowsWebPublic() ? $rule : $rule->except([Visibility::Open]);
    }

    private static function allowsWebPublic(): bool
    {
        return (bool) config('openpne.timeline.allow_web_public');
    }
}
