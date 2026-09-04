<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Gadgets\GadgetKind;

/** OpenPNE 3 birthdayBox; it emitted no wrapper id, so partId stays null. */
class BirthdayBoxGadget extends GadgetKind
{
    public function name(): string
    {
        return 'birthdayBox';
    }

    public function label(): string
    {
        return __('Birthday Box');
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
