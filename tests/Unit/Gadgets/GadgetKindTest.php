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
            // The home diary lists share OpenPNE 3's homeRecentList_ id; diaryMemberList had none.
            'diaryFriendList' => ['diaryFriendList', 'homeRecentList_7'],
            'diaryList' => ['diaryList', 'homeRecentList_7'],
            'diaryCommentHistory' => ['diaryCommentHistory', 'homeRecentList_7'],
            'diaryMyList' => ['diaryMyList', 'homeRecentList_7'],
            'diaryMemberList' => ['diaryMemberList', null],
            // The community recent lists share OpenPNE 3's homeRecentList_ id (all home-only).
            'recentCommunityTopicComment' => ['recentCommunityTopicComment', 'homeRecentList_7'],
            'recentCommunityEventComment' => ['recentCommunityEventComment', 'homeRecentList_7'],
            'recentCommunityTopicCommentSns' => ['recentCommunityTopicCommentSns', 'homeRecentList_7'],
            'recentCommunityEventCommentSns' => ['recentCommunityEventCommentSns', 'homeRecentList_7'],
            // The timeline gadgets each keep their own OpenPNE 3 wrapper id.
            'timelineAll' => ['timelineAll', 'homeAllTimeline_7'],
            'timelineFriend' => ['timelineFriend', 'homeFriendTimeline_7'],
            'timelineProfile' => ['timelineProfile', 'profileTimeline_7'],
            // activityBox and allMemberActivityBox share OpenPNE 3's activityBox_ id (a common partial built it).
            'activityBox' => ['activityBox', 'activityBox_7'],
            'allMemberActivityBox' => ['allMemberActivityBox', 'activityBox_7'],
            // birthdayBox is a bare greeting image; OpenPNE 3 emitted no wrapper id.
            'birthdayBox' => ['birthdayBox', null],
            'searchBox' => ['searchBox', 'searchLine_7'],
            'linkListBox' => ['linkListBox', null],
            'languageSelecterBox' => ['languageSelecterBox', null],
            'sideBanner' => ['sideBanner', null],
            'loginForm' => ['loginForm', null],
        ];
    }

    #[DataProvider('partIdCases')]
    public function test_part_id_matches_openpne3(string $name, ?string $expected): void
    {
        $this->assertSame($expected, GadgetKindRegistry::find($name)?->partId(7));
    }
}
