<?php

namespace Tests\Unit\Upgrade;

use App\Upgrade\Runner\EmojiMap;
use PHPUnit\Framework\TestCase;

class EmojiMapTest extends TestCase
{
    /**
     * Spot checks against the OpenPNE 3 Img.php Japanese names for these ids:
     * i:1 晴れ, i:98 三日月, i:33 車（セダン）, i:136 黒ハート, e:1 ！(warning),
     * e:51 ハート, e:94 カメラ, s:4 お父さん, s:327 パン.
     */
    public function test_known_ids_map_to_expected_unicode(): void
    {
        $this->assertSame("\u{2600}", EmojiMap::convert('[i:1]'));
        $this->assertSame("\u{1F319}", EmojiMap::convert('[i:98]'));
        $this->assertSame("\u{1F697}", EmojiMap::convert('[i:33]'));
        $this->assertSame("\u{2764}", EmojiMap::convert('[i:136]'));
        $this->assertSame("\u{26A0}", EmojiMap::convert('[e:1]'));
        $this->assertSame("\u{2764}", EmojiMap::convert('[e:51]'));
        $this->assertSame("\u{1F4F7}", EmojiMap::convert('[e:94]'));
        $this->assertSame("\u{1F468}", EmojiMap::convert('[s:4]'));
        $this->assertSame("\u{1F35E}", EmojiMap::convert('[s:327]'));
    }

    public function test_multi_codepoint_mappings(): void
    {
        // i:123 シャープダイヤル = keycap #, e:90 ＵＳＡ = regional indicator pair.
        $this->assertSame("\u{0023}\u{20E3}", EmojiMap::convert('[i:123]'));
        $this->assertSame("\u{1F1FA}\u{1F1F8}", EmojiMap::convert('[e:90]'));
    }

    public function test_unmapped_id_stays_literal(): void
    {
        $this->assertSame('[i:999]', EmojiMap::convert('[i:999]'));
        // i:108 exists in OP3 (iモード logo) but has no Unicode equivalent.
        $this->assertSame('[i:108]', EmojiMap::convert('[i:108]'));
    }

    public function test_mixed_text_converts_only_mapped_codes(): void
    {
        $this->assertSame(
            "こんにちは\u{2600}です[i:999]",
            EmojiMap::convert('こんにちは[i:1]です[i:999]'),
        );
    }

    public function test_malformed_codes_are_untouched(): void
    {
        $this->assertSame('[i:]', EmojiMap::convert('[i:]'));
        $this->assertSame('[x:1]', EmojiMap::convert('[x:1]'));
        $this->assertSame('[i:1', EmojiMap::convert('[i:1'));
    }

    public function test_table_is_structurally_sound(): void
    {
        $this->assertSame(['i', 'e', 's'], array_keys(EmojiMap::TABLE));

        foreach (EmojiMap::TABLE as $carrier => $entries) {
            $this->assertNotEmpty($entries, "carrier {$carrier} is empty");

            foreach ($entries as $id => $value) {
                $this->assertIsInt($id, "carrier {$carrier} key {$id} is not int");
                $this->assertNotSame('', $value, "carrier {$carrier} id {$id} is empty");
                $this->assertTrue(
                    mb_check_encoding($value, 'UTF-8'),
                    "carrier {$carrier} id {$id} is not valid UTF-8",
                );
            }
        }
    }
}
