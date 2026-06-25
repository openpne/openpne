<?php

declare(strict_types=1);

namespace Tests\Unit\Gadgets;

use App\Gadgets\GadgetLayout;
use PHPUnit\Framework\TestCase;

/** The OpenPNE 3 type ↔ (context, zone) SSoT the gadget upgrade splits on. */
class GadgetLayoutTest extends TestCase
{
    public function test_op3_type_replays_the_openpne3_naming_rule(): void
    {
        // home ("gadget") uses the bare zone; other contexts camelize "{op3Key}_{zone}".
        $this->assertSame('top', GadgetLayout::op3Type('home', 'top'));
        $this->assertSame('contents', GadgetLayout::op3Type('home', 'contents'));
        $this->assertSame('profileSideMenu', GadgetLayout::op3Type('profile', 'sideMenu'));
        $this->assertSame('loginContents', GadgetLayout::op3Type('login', 'contents'));
        $this->assertSame('sideBannerContents', GadgetLayout::op3Type('sidebanner', 'contents'));
    }

    public function test_type_map_covers_ported_pc_types_and_excludes_the_rest(): void
    {
        $map = GadgetLayout::op3TypeMap();

        $this->assertSame(['context' => 'home', 'zone' => 'top'], $map['top']);
        $this->assertSame(['context' => 'profile', 'zone' => 'sideMenu'], $map['profileSideMenu']);
        $this->assertSame(['context' => 'login', 'zone' => 'bottom'], $map['loginBottom']);
        $this->assertSame(['context' => 'sidebanner', 'zone' => 'contents'], $map['sideBannerContents']);

        // Non-PC types never appear in the map (the upgrade filter drops them).
        $this->assertArrayNotHasKey('mobileTop', $map);
        $this->assertArrayNotHasKey('smartphoneContents', $map);
    }

    public function test_letter_is_the_layout_suffix_with_an_unknown_fallback(): void
    {
        $this->assertSame('A', GadgetLayout::letter('layoutA'));
        $this->assertSame('C', GadgetLayout::letter('layoutC'));
        $this->assertSame('D', GadgetLayout::letter('layoutD'));
        $this->assertSame('A', GadgetLayout::letter('bogus')); // unknown falls back to A, like zones()
    }

    public function test_sidebanner_is_a_fixed_single_zone_context(): void
    {
        $this->assertFalse(GadgetLayout::isSelectable('sidebanner'));
        $this->assertSame('layoutD', GadgetLayout::defaultLayout('sidebanner'));
        $this->assertSame(['contents'], GadgetLayout::zones('layoutD'));
        $this->assertNull(GadgetLayout::layoutSettingKey('sidebanner'));
    }
}
