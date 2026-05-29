<?php

namespace Tests\Unit\Diary;

use App\Features\Diary\Visibility;
use PHPUnit\Framework\TestCase;

class VisibilityOrderingTest extends TestCase
{
    public function test_values_are_monotonically_ordered_open_to_private(): void
    {
        // Range comparison visibility <= clearance relies on this ordering.
        $this->assertLessThan(Visibility::Members->value, Visibility::Open->value);
        $this->assertLessThan(Visibility::Friends->value, Visibility::Members->value);
        $this->assertLessThan(Visibility::Private->value, Visibility::Friends->value);
    }

    public function test_open_value_is_zero(): void
    {
        // Open=0 is an OpenPNE 4 invention (not a legacy public_flag value).
        $this->assertSame(0, Visibility::Open->value);
    }

    public function test_members_friends_private_match_openpne3_public_flag(): void
    {
        $this->assertSame(1, Visibility::Members->value);
        $this->assertSame(2, Visibility::Friends->value);
        $this->assertSame(3, Visibility::Private->value);
    }

    public function test_slug_returns_lowercase_string_for_each_case(): void
    {
        $this->assertSame('open', Visibility::Open->slug());
        $this->assertSame('members', Visibility::Members->slug());
        $this->assertSame('friends', Visibility::Friends->slug());
        $this->assertSame('private', Visibility::Private->slug());
    }
}
