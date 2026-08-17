<?php

namespace Tests\Feature\Profile;

use App\Models\Diary;
use App\Models\DiaryImage;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use App\Models\MemberImage;
use App\Models\TimelinePost;
use App\Models\TimelinePostImage;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The experiment switch (SnsSettingKey::ModernUnifiedHome) on a page about somebody else. It pins
 * both sides — off, the digest profile is what it was; on, the new page carries only what the same
 * viewer-scoped queries already return — and, because viewer and owner are now different members,
 * the clearance matrix the home slice had no way to exercise.
 */
class UnifiedMemberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['openpne.surface_mode' => 'modern_default']);
    }

    private function unifiedOn(): void
    {
        $this->setSnsSetting(SnsSettingKey::ModernUnifiedHome, true);
        $this->freshRequestState();
    }

    /** The bidirectional mirror `friendships` is read through, both rows. `$at` defaults to useCurrent. */
    private function makeFriends(Member $a, Member $b, ?string $at = null): void
    {
        $when = $at === null ? [] : ['created_at' => $at];

        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey(), ...$when],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey(), ...$when],
        ]);
    }

    private function block(Member $blocker, Member $blocked): void
    {
        DB::table('member_blocks')->insert(['blocker_id' => $blocker->getKey(), 'blocked_id' => $blocked->getKey()]);
    }

    /** A diary of the owner's, posted at $at, with $images pictures attached. */
    private function diary(Member $owner, string $at, int $images = 0, Visibility $visibility = Visibility::Members, string $title = 'a-title'): Diary
    {
        $diary = Diary::factory()->create([
            'member_id' => $owner->getKey(),
            'title' => $title,
            'visibility' => $visibility,
            'created_at' => Carbon::parse($at),
            'updated_at' => Carbon::parse($at),
        ]);

        for ($number = 1; $number <= $images; $number++) {
            DiaryImage::factory()->create(['diary_id' => $diary->getKey(), 'number' => $number]);
        }

        return $diary;
    }

    /** A timeline post of the owner's, posted at $at, with $images pictures attached. */
    private function timelinePost(Member $owner, string $at, int $images = 0, Visibility $visibility = Visibility::Members): TimelinePost
    {
        $post = TimelinePost::factory()->create([
            'member_id' => $owner->getKey(),
            'visibility' => $visibility,
            'created_at' => Carbon::parse($at),
            'updated_at' => Carbon::parse($at),
        ]);

        for ($number = 1; $number <= $images; $number++) {
            TimelinePostImage::factory()->create(['timeline_post_id' => $post->getKey(), 'number' => $number]);
        }

        return $post;
    }

    /**
     * ProfileStats' four counts — what the digest gathers and what the unified page must never make
     * anyone pay for. The `aggregate` alias is Laravel's `->count()` reading (the quoting is the
     * driver's, hence the pattern), which tells them apart both from the shell's badge counts, which
     * count other tables, and from every `withCount` subquery, which is aliased `*_count` and rides
     * inside a select the page does want.
     *
     * @return list<array{query: string}>
     */
    private function digestCountQueries(): array
    {
        return array_values(array_filter(
            DB::getQueryLog(),
            fn (array $q): bool => preg_match('/count\(\*\) as .?aggregate/', $q['query']) === 1
                && (str_contains($q['query'], 'diaries')
                    || str_contains($q['query'], 'timeline_posts')
                    || str_contains($q['query'], 'friendships')
                    || str_contains($q['query'], 'groups')),
        ));
    }

    public function test_the_shipped_profile_is_untouched_while_the_switch_is_off(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members]);

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('member/show')
                ->where('profile.owner.id', $owner->getKey())
                ->has('profile.fields')
                ->where('digest.stats.diaries', 1)
                ->has('digest.recentDiaries')
                ->has('digest.friends')
                ->has('digest.groups')
                // The unified payload's own keys are absent while the switch is off.
                ->missing('groups')
                ->missing('friends')
                ->missing('recentPhotos')
                ->missing('recentDiaries')
            );
    }

    public function test_the_switch_swaps_the_page_without_gathering_the_digest(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $this->unifiedOn();

        DB::enableQueryLog();
        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('unified/member')
                ->has('fields')
                ->has('groups')
                ->has('friends')
                ->has('recentPhotos')
                ->has('recentDiaries')
                ->where('profile.id', $owner->getKey())
                ->where('profile.name', $owner->name)
                ->where('profile.isAi', false)
                ->where('profile.isSelf', false)
                // What the action row is drawn from: the same relationship the digest profile reads.
                ->where('profile.friendStatus', 'none')
                // The digest and the counts its header carried are gone with the page that showed them.
                ->missing('digest')
                ->missing('profile.stats')
            );
        $counts = $this->digestCountQueries();
        DB::disableQueryLog();

        $this->assertSame([], $counts, 'the digest counts still ran for a page that does not show them');
    }

    public function test_the_hero_takes_the_two_cover_rungs(): void
    {
        $owner = Member::factory()->create();
        MemberImage::factory()->create(['member_id' => $owner->getKey()]);
        $viewer = Member::factory()->create();
        $this->unifiedOn();

        // A wide crop across the content column, so it takes the cover sizes rather than the digest
        // profile's 180px round face.
        $file = $owner->fresh()->avatar->file;

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('profile.avatarUrl', $file->thumbnailUrl(640, 640, square: true))
                ->where('profile.avatarUrlLarge', $file->thumbnailUrl(1200, 1200, square: true))
            );
    }

    public function test_the_owners_own_page_offers_the_self_entries_instead_of_a_relationship(): void
    {
        $owner = Member::factory()->create();
        $this->unifiedOn();

        $this->actingAs($owner)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('unified/member')
                ->where('profile.isSelf', true)
                ->where('profile.friendStatus', null)
            );
    }

    public function test_a_guest_keeps_the_shipped_profile(): void
    {
        // The unified page's sections are the auth-only ones the digest is already withheld for.
        $owner = Member::factory()->create(['profile_visibility' => Visibility::Open]);
        $this->unifiedOn();

        $this->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('member/show')
                ->where('digest', null)
            );
    }

    public function test_the_owners_block_still_denies_the_whole_page(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $this->block($owner, $viewer);
        $this->unifiedOn();

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")->assertNotFound();
    }

    public function test_a_viewer_who_blocks_the_owner_gets_the_page_with_no_relationship_entry(): void
    {
        // The reverse direction renders, as it does on the digest profile: the friend-link form would
        // reject the request either way, so there is no entry to offer.
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $this->block($viewer, $owner);
        $this->unifiedOn();

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('unified/member')
                ->where('profile.friendStatus', null)
            );
    }

    public function test_a_friends_only_diary_reaches_a_friend_and_not_a_stranger(): void
    {
        $owner = Member::factory()->create();
        $friend = Member::factory()->create();
        $stranger = Member::factory()->create();
        $this->makeFriends($owner, $friend);
        $this->diary($owner, '2026-08-16 10:00:00', images: 1, visibility: Visibility::Friends, title: 'friends-only-entry');
        $this->unifiedOn();

        $this->actingAs($friend)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('recentDiaries', 1)
                ->where('recentDiaries.0.title', 'friends-only-entry')
                ->has('recentPhotos', 1)
            );

        $this->actingAs($stranger)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('recentDiaries', [])
                ->where('recentPhotos', [])
            )
            ->assertDontSee('friends-only-entry');
    }

    public function test_a_self_only_diary_reaches_nobody_but_its_author(): void
    {
        $owner = Member::factory()->create();
        $friend = Member::factory()->create();
        $this->makeFriends($owner, $friend);
        $this->diary($owner, '2026-08-16 10:00:00', images: 1, visibility: Visibility::Private, title: 'self-only-entry');
        $this->unifiedOn();

        $this->actingAs($friend)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('recentDiaries', [])
                ->where('recentPhotos', [])
            )
            ->assertDontSee('self-only-entry');

        $this->actingAs($owner)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('recentDiaries', 1)
                ->where('recentDiaries.0.title', 'self-only-entry')
                ->has('recentPhotos', 1)
            );
    }

    public function test_a_timeline_post_above_the_viewers_clearance_leaves_no_picture_behind(): void
    {
        $owner = Member::factory()->create();
        $friend = Member::factory()->create();
        $stranger = Member::factory()->create();
        $this->makeFriends($owner, $friend);
        $open = $this->timelinePost($owner, '2026-08-16 09:00:00', images: 1);
        $restricted = $this->timelinePost($owner, '2026-08-16 10:00:00', images: 1, visibility: Visibility::Friends);
        $this->unifiedOn();

        $this->actingAs($friend)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('recentPhotos', 2)
                ->where('recentPhotos.0.href', "/timeline/{$restricted->getKey()}")
                ->where('recentPhotos.1.href', "/timeline/{$open->getKey()}")
            );

        $this->actingAs($stranger)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('recentPhotos', 1)
                ->where('recentPhotos.0.href', "/timeline/{$open->getKey()}")
            );
    }

    public function test_the_groups_and_people_are_the_owners_not_the_viewers(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $ownersFriend = Member::factory()->create();
        $this->makeFriends($owner, $ownersFriend);

        $ownersGroup = Group::factory()->create();
        GroupMember::factory()->create(['group_id' => $ownersGroup->getKey(), 'member_id' => $owner->getKey()]);
        // The viewer's own group and own friend: neither belongs on a page about somebody else.
        $viewersGroup = Group::factory()->create();
        GroupMember::factory()->create(['group_id' => $viewersGroup->getKey(), 'member_id' => $viewer->getKey()]);
        $this->makeFriends($viewer, Member::factory()->create());

        $this->unifiedOn();

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('groups', 1)
                ->where('groups.0.id', $ownersGroup->getKey())
                ->where('groups.0.name', $ownersGroup->name)
                ->where('groups.0.href', "/groups/{$ownersGroup->getKey()}")
                ->has('friends', 1)
                ->where('friends.0.id', $ownersFriend->getKey())
                ->where('friends.0.name', $ownersFriend->name)
                ->where('friends.0.isAi', false)
                ->where('friends.0.href', "/member/{$ownersFriend->getKey()}")
            );
    }

    /**
     * The owner's faces, in the owner's order: the nine newest of their friendships, newest first, as on
     * their own home. This page is the one that shows somebody else's, so the order is deliberate here
     * and not inherited — it is the fact the section adds over the profile grid it replaces.
     */
    public function test_the_faces_are_the_owners_nine_newest_friendships(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        // Dates scattered across the order the members were created, so the expected row is neither the
        // members ascending nor descending: only the friendship's own timestamp produces it.
        $friends = [];

        foreach ([5, 11, 3, 8, 1, 9, 6, 2, 10, 4, 7] as $day) {
            $friend = Member::factory()->create();
            $this->makeFriends($owner, $friend, sprintf('2026-08-%02d 10:00:00', $day));
            $friends[] = $friend;
        }

        $this->unifiedOn();
        $expected = array_map(fn (int $made): int => $friends[$made]->getKey(), [1, 8, 5, 3, 10, 6, 0, 9, 2]);

        $page = $this->actingAs($viewer)->get("/member/{$owner->getKey()}")->viewData('page');

        $this->assertSame($expected, array_column($page['props']['friends'], 'id'));
    }

    public function test_the_group_unit_off_empties_its_section_without_reading_it(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $group = Group::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $owner->getKey()]);

        $this->setSnsSetting(SnsSettingKey::FeatureGroupEnabled, false);
        $this->unifiedOn();

        DB::enableQueryLog();
        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('groups', []));
        $groupQueries = array_filter(
            DB::getQueryLog(),
            fn (array $q): bool => str_contains($q['query'], 'groups') || str_contains($q['query'], 'group_members'),
        );
        DB::disableQueryLog();

        $this->assertSame([], $groupQueries, 'a switched-off unit still read its table');
    }

    public function test_the_friend_unit_off_empties_its_section_and_the_relationship(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create();
        $this->makeFriends($owner, $friend);

        $this->setSnsSetting(SnsSettingKey::FeatureFriendEnabled, false);
        $this->unifiedOn();

        DB::enableQueryLog();
        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('friends', [])
                // Nothing about the relationship travels either, so the action row has no entry to
                // draw and the switched-off unit stays unobservable.
                ->where('profile.friendStatus', null)
                ->where('enabledFeatures.friend', false)
            );
        // The roster read is what goes with the unit. The `exists` probes stay: a friends-only diary
        // is friends-only whatever the section is doing, so content clearance still has to ask.
        $friendQueries = array_filter(
            DB::getQueryLog(),
            fn (array $q): bool => str_contains($q['query'], 'friendships') && ! str_contains($q['query'], 'select exists('),
        );
        DB::disableQueryLog();

        $this->assertSame([], $friendQueries, 'a switched-off unit still listed its rows');
    }

    public function test_the_diary_unit_off_empties_its_half_without_reading_it(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $this->diary($owner, '2026-08-16 10:00:00', images: 1);
        $post = $this->timelinePost($owner, '2026-08-16 09:00:00', images: 1);

        $this->setSnsSetting(SnsSettingKey::FeatureDiaryEnabled, false);
        $this->unifiedOn();

        DB::enableQueryLog();
        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('recentDiaries', [])
                ->has('recentPhotos', 1)
                ->where('recentPhotos.0.source', 'timeline')
                ->where('recentPhotos.0.href', "/timeline/{$post->getKey()}")
            );
        $diaryQueries = array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'diaries'));
        DB::disableQueryLog();

        $this->assertSame([], $diaryQueries, 'a switched-off unit still read its table');
    }

    public function test_the_timeline_unit_off_empties_its_half_without_reading_it(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $diary = $this->diary($owner, '2026-08-16 09:00:00', images: 1);
        $this->timelinePost($owner, '2026-08-16 10:00:00', images: 1);

        $this->setSnsSetting(SnsSettingKey::FeatureTimelineEnabled, false);
        $this->unifiedOn();

        DB::enableQueryLog();
        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('recentPhotos', 1)
                ->where('recentPhotos.0.source', 'diary')
                ->where('recentPhotos.0.href', "/diary/{$diary->getKey()}")
            );
        $postQueries = array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'timeline_posts'));
        DB::disableQueryLog();

        $this->assertSame([], $postQueries, 'a switched-off unit still read its table');
    }

    public function test_the_message_unit_off_reaches_the_client_as_a_switched_off_unit(): void
    {
        // The action row reads the shared unit map, as the digest profile's does — there is no
        // per-page prop for it to disagree with.
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();

        $this->setSnsSetting(SnsSettingKey::FeatureDirectMessageEnabled, false);
        $this->unifiedOn();

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('unified/member')
                ->where('enabledFeatures.directMessage', false)
            );
    }

    public function test_photos_mix_the_two_sources_newest_parent_first(): void
    {
        $owner = Member::factory()->create();
        $friend = Member::factory()->create();
        $this->makeFriends($owner, $friend);
        $oldest = $this->diary($owner, '2026-08-16 10:00:00', images: 1, visibility: Visibility::Friends);
        $middle = $this->timelinePost($owner, '2026-08-16 10:00:05', images: 1);
        // Same second as each other, so only the parent id can separate them — and the later row
        // (higher id) is the newer post.
        $tied = $this->diary($owner, '2026-08-16 10:00:10', images: 1);
        $newest = $this->diary($owner, '2026-08-16 10:00:10', images: 1);
        $this->unifiedOn();

        $this->actingAs($friend)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('recentPhotos', 4)
                ->where('recentPhotos.0.href', "/diary/{$newest->getKey()}")
                ->where('recentPhotos.1.href', "/diary/{$tied->getKey()}")
                ->where('recentPhotos.2.href', "/timeline/{$middle->getKey()}")
                ->where('recentPhotos.3.href', "/diary/{$oldest->getKey()}")
            );
    }

    public function test_the_grid_caps_at_eight_without_disturbing_the_order(): void
    {
        $owner = Member::factory()->create();
        $friend = Member::factory()->create();
        $this->makeFriends($owner, $friend);
        // Four pictures on the newest post, then eight older ones with a picture each: twelve in all,
        // so the cap has to cut somewhere and must cut the oldest.
        $rich = $this->diary($owner, '2026-08-16 10:10:00', images: 4);
        $posts = [];
        foreach (range(1, 8) as $minute) {
            $posts[$minute] = $this->timelinePost($owner, "2026-08-16 10:0{$minute}:00", images: 1);
        }
        $this->unifiedOn();

        $expected = [
            // The rich parent's own pictures keep the order their author arranged them in.
            ...array_fill(0, 4, "/diary/{$rich->getKey()}"),
            ...array_map(fn (int $minute): string => "/timeline/{$posts[$minute]->getKey()}", [8, 7, 6, 5]),
        ];

        $this->actingAs($friend)->get("/member/{$owner->getKey()}")
            ->assertInertia(function (AssertableInertia $page) use ($expected) {
                $page->has('recentPhotos', 8);
                foreach ($expected as $i => $href) {
                    $page->where("recentPhotos.{$i}.href", $href);
                }

                return $page;
            });
    }
}
