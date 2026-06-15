<?php

declare(strict_types=1);

namespace Tests\Unit\Gadgets;

use App\Gadgets\GadgetKindRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Each kind's OpenPNE 3-compatible DOM id (the custom-CSS seam). */
class GadgetKindTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function partIdCases(): array
    {
        // OpenPNE 3 part ids, verified against the pc_frontend component templates. The prefix is
        // often not the gadget name (information, friendList, communityList, searchLine); profileListBox
        // used a fixed `profile`; bare-form kinds had no id.
        return [
            'freeArea' => ['freeArea', 'freeArea_7'],
            'informationBox' => ['informationBox', 'information_7'],
            'memberImageBox' => ['memberImageBox', 'memberImageBox_7'],
            'friendListBox' => ['friendListBox', 'friendList_7'],
            'communityJoinListBox' => ['communityJoinListBox', 'communityList_7'],
            'profileListBox' => ['profileListBox', 'profile'],
            'searchBox' => ['searchBox', 'searchLine_7'],
            'linkListBox' => ['linkListBox', null],
            'languageSelecterBox' => ['languageSelecterBox', null],
            'loginForm' => ['loginForm', null],
        ];
    }

    #[DataProvider('partIdCases')]
    public function test_part_id_matches_openpne3(string $name, ?string $expected): void
    {
        $this->assertSame($expected, GadgetKindRegistry::find($name)?->partId(7));
    }
}
