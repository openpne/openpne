<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Gadgets\GadgetKind;

/**
 * OpenPNE 3 birthdayBox: a birthday greeting image rendered by context. On the home it appears only
 * on the viewer's own birthday; on a profile it appears on the owner's birthday and the three days
 * before. No config, and OpenPNE 3 emitted no wrapper id (base partId null).
 */
class BirthdayBoxGadget extends GadgetKind
{
    public function name(): string
    {
        return 'birthdayBox';
    }

    public function description(): string
    {
        return __('A birthday greeting shown on the birthday (and, on a profile, the days just before).');
    }

    public function component(): string
    {
        return 'gadget.birthday-box';
    }

    public function contexts(): array
    {
        return ['home', 'profile'];
    }
}
