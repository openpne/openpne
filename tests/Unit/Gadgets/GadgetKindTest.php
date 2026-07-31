<?php

declare(strict_types=1);

namespace Tests\Unit\Gadgets;

use App\Gadgets\GadgetKindRegistry;
use App\Support\Feature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Each kind's OpenPNE 3-compatible DOM id (the custom-CSS seam), and the unit whose toggle hides it. */
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

    /**
     * The whole registry, so a kind added later must state its unit — or state that it has none.
     *
     * @return array<string, ?Feature>
     */
    private static function featureMap(): array
    {
        return [
            // The diary lists and the comment history all read the diary module.
            'diaryList' => Feature::Diary,
            'diaryFriendList' => Feature::Diary,
            'diaryMyList' => Feature::Diary,
            'diaryMemberList' => Feature::Diary,
            'diaryCommentHistory' => Feature::Diary,
            // The timeline slices and both activity boxes render timeline posts.
            'timelineAll' => Feature::Timeline,
            'timelineFriend' => Feature::Timeline,
            'timelineProfile' => Feature::Timeline,
            'activityBox' => Feature::Timeline,
            'allMemberActivityBox' => Feature::Timeline,
            // The board / calendar lists follow their own unit (and communities through it).
            'recentCommunityTopicComment' => Feature::CommunityTopic,
            'recentCommunityTopicCommentSns' => Feature::CommunityTopic,
            'recentCommunityEventComment' => Feature::CommunityEvent,
            'recentCommunityEventCommentSns' => Feature::CommunityEvent,
            'communityJoinListBox' => Feature::Community,
            'friendListBox' => Feature::Friend,
            // birthdayBox lists birthdays, not friendships, so friends being off does not silence it.
            'birthdayBox' => null,
            'freeArea' => null,
            'informationBox' => null,
            'memberImageBox' => null,
            'profileListBox' => null,
            'searchBox' => null,
            'linkListBox' => null,
            'languageSelecterBox' => null,
            'sideBanner' => null,
            'loginForm' => null,
        ];
    }

    /** @return array<string, array{0: string, 1: ?Feature}> */
    public static function featureCases(): array
    {
        $cases = [];
        foreach (self::featureMap() as $name => $feature) {
            $cases[$name] = [$name, $feature];
        }

        return $cases;
    }

    #[DataProvider('featureCases')]
    public function test_kind_declares_the_unit_that_hides_it(string $name, ?Feature $expected): void
    {
        $this->assertSame($expected, GadgetKindRegistry::find($name)?->feature());
    }

    public function test_every_registered_kind_is_covered_by_the_feature_map(): void
    {
        $registered = array_keys(GadgetKindRegistry::all());
        $mapped = array_keys(self::featureMap());
        sort($registered);
        sort($mapped);

        $this->assertSame($registered, $mapped,
            'A kind was added or removed: state its feature unit (or null) in featureMap().');
    }

    /**
     * The second unit a kind needs, per context — a friend lens on top of the unit that owns the
     * kind. Every kind and context absent here must need nothing beyond its own unit.
     *
     * @return array<string, array<string, Feature>>
     */
    private static function dependencyMap(): array
    {
        return [
            'diaryFriendList' => ['home' => Feature::Friend],
            'timelineFriend' => ['home' => Feature::Friend],
            // The one context-dependent kind: the home box is the friend feed, the profile box is
            // the owner's own timeline.
            'activityBox' => ['home' => Feature::Friend],
        ];
    }

    /** Walks every kind in every context it offers, so a lens added later cannot skip the map. */
    public function test_only_the_friend_lenses_depend_on_a_second_unit(): void
    {
        foreach (GadgetKindRegistry::all() as $name => $kind) {
            foreach ($kind->contexts() as $context) {
                $this->assertSame(self::dependencyMap()[$name][$context] ?? null, $kind->dependsOn($context),
                    "{$name} in the {$context} context depends on a unit the map does not state");
            }
        }
    }
}
