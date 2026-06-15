<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Gadgets\GadgetKind;

/** A member-search form (OpenPNE 3 default/searchBox). */
class SearchBoxGadget extends GadgetKind
{
    public function name(): string
    {
        return 'searchBox';
    }

    public function contexts(): array
    {
        return ['home'];
    }

    public function component(): string
    {
        return 'gadget.search-box';
    }
}
