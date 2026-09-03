<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Gadgets\GadgetKind;

/**
 * The Classic side banner (OpenPNE 3 default/sideBanner): emits op_banner's side placement —
 * side_after when a member is signed in, else side_before — with no wrapper markup.
 *
 * Public (ANYONE), a deliberate divergence from OpenPNE 3's members-only gadget default: its own
 * template branches to side_before for guests, so a members-only privilege would leave that guest
 * placement permanently unreachable.
 */
class SideBannerGadget extends GadgetKind
{
    public function name(): string
    {
        return 'sideBanner';
    }

    public function label(): string
    {
        return __('Side banner');
    }

    public function description(): string
    {
        return __('The side banner set on the Banner page.');
    }

    public function contexts(): array
    {
        return ['sidebanner'];
    }

    public function component(): string
    {
        return 'gadget.side-banner';
    }

    public function viewablePrivilege(string $context): int
    {
        return self::ANYONE;
    }
}
