<?php

namespace Tests\Unit\Support;

use App\Support\Visibility;
use PHPUnit\Framework\TestCase;

class VisibilityTest extends TestCase
{
    public function test_values_are_monotonically_ordered_open_to_private(): void
    {
        // Range comparison visibility <= clearance relies on this ordering.
        $this->assertLessThan(Visibility::Members->value, Visibility::Open->value);
        $this->assertLessThan(Visibility::Friends->value, Visibility::Members->value);
        $this->assertLessThan(Visibility::Private->value, Visibility::Friends->value);
        $this->assertSame(0, Visibility::Open->value);
    }

    public function test_maps_openpne3_public_flag(): void
    {
        // SNS=1 → Members, friend=2 → Friends, private=3 → Private, web=4 → Open.
        $this->assertSame(Visibility::Members, Visibility::fromOpenPne3PublicFlag(1));
        $this->assertSame(Visibility::Friends, Visibility::fromOpenPne3PublicFlag(2));
        $this->assertSame(Visibility::Private, Visibility::fromOpenPne3PublicFlag(3));
        $this->assertSame(Visibility::Open, Visibility::fromOpenPne3PublicFlag(4));
        // OpenPNE's invalid 0 default and NULL fall back to Members.
        $this->assertSame(Visibility::Members, Visibility::fromOpenPne3PublicFlag(0));
        $this->assertSame(Visibility::Members, Visibility::fromOpenPne3PublicFlag(null));
    }

    public function test_slug_returns_lowercase_string_for_each_case(): void
    {
        $this->assertSame('open', Visibility::Open->slug());
        $this->assertSame('members', Visibility::Members->slug());
        $this->assertSame('friends', Visibility::Friends->slug());
        $this->assertSame('private', Visibility::Private->slug());
    }
}
