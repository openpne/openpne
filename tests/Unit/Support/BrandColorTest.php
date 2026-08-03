<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\BrandColor;
use PHPUnit\Framework\TestCase;

class BrandColorTest extends TestCase
{
    public function test_only_a_six_digit_hex_color_is_accepted(): void
    {
        $this->assertTrue(BrandColor::isValid('#2563eb'));
        $this->assertTrue(BrandColor::isValid('#ABCDEF'));

        $this->assertFalse(BrandColor::isValid(''));
        $this->assertFalse(BrandColor::isValid('2563eb'));
        $this->assertFalse(BrandColor::isValid('#25e'));
        $this->assertFalse(BrandColor::isValid('#2563ebff'));
        $this->assertFalse(BrandColor::isValid('#2563eg'));
        $this->assertFalse(BrandColor::isValid('red'));
        // Anchored, so nothing may ride along into the inline style attribute.
        $this->assertFalse(BrandColor::isValid("#2563eb\n; background: url(x)"));
    }

    public function test_foreground_is_white_on_a_dark_color_and_black_on_a_light_one(): void
    {
        $this->assertSame('#ffffff', BrandColor::readableForeground('#000000'));
        $this->assertSame('#ffffff', BrandColor::readableForeground('#2563eb'));

        $this->assertSame('#000000', BrandColor::readableForeground('#ffffff'));
        $this->assertSame('#000000', BrandColor::readableForeground('#ffe400'));
    }

    public function test_a_mid_tone_color_takes_the_side_that_still_clears_aa(): void
    {
        // #0088aa is the case pure black/white exists for: 5.10:1 on black, where the white/slate-900
        // pair the badges used to offer tops out at 4.34:1 — below AA on both sides.
        $this->assertSame('#000000', BrandColor::readableForeground('#0088aa'));
    }

    public function test_an_unusable_value_falls_back_to_white(): void
    {
        $this->assertSame('#ffffff', BrandColor::readableForeground(''));
        $this->assertSame('#ffffff', BrandColor::readableForeground('nope'));
    }
}
