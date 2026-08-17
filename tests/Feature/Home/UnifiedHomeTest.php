<?php

namespace Tests\Feature\Home;

use App\Models\Diary;
use App\Models\DiaryImage;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Models\TimelinePostImage;
use App\Services\SnsSettingService;
use App\Support\Look;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The look pins BOTH sides: under `standard` the previous home is byte-for-byte what it was, and
 * under `unified` the new page carries only what the same viewer-scoped queries already return.
 */
class UnifiedHomeTest extends TestCase
{
    use RefreshDatabase;

    /** Every prop the digest dashboard ships — the payload the look must leave alone while standard. */
    private const DASHBOARD_PROPS = ['announcements', 'talkRooms', 'diaries', 'timeline', 'groupActivity', 'myDiaries'];

    private function unifiedOn(): void
    {
        $this->setSnsSetting(SnsSettingKey::DefaultLook, Look::Unified);
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

    /** A diary of the viewer's, posted at $at, with $images pictures attached. */
    private function diary(Member $viewer, string $at, int $images = 0): Diary
    {
        $diary = Diary::factory()->create([
            'member_id' => $viewer->getKey(),
            'visibility' => Visibility::Private,
            'created_at' => Carbon::parse($at),
            'updated_at' => Carbon::parse($at),
        ]);

        for ($number = 1; $number <= $images; $number++) {
            DiaryImage::factory()->create(['diary_id' => $diary->getKey(), 'number' => $number]);
        }

        return $diary;
    }

    /** A timeline post of the viewer's, posted at $at, with $images pictures attached. */
    private function timelinePost(Member $viewer, string $at, int $images = 0): TimelinePost
    {
        $post = TimelinePost::factory()->create([
            'member_id' => $viewer->getKey(),
            'visibility' => Visibility::Private,
            'created_at' => Carbon::parse($at),
            'updated_at' => Carbon::parse($at),
        ]);

        for ($number = 1; $number <= $images; $number++) {
            TimelinePostImage::factory()->create(['timeline_post_id' => $post->getKey(), 'number' => $number]);
        }

        return $post;
    }

    public function test_the_shipped_home_is_untouched_while_the_switch_is_off(): void
    {
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(function ($page) {
                $page->component('dashboard');
                foreach (self::DASHBOARD_PROPS as $prop) {
                    $page->has($prop);
                }

                return $page->missing('profile')->missing('recentPhotos')->missing('recentDiaries')
                    ->missing('groups')->missing('friends');
            });
    }

    public function test_the_switch_swaps_the_page_and_drops_the_digests(): void
    {
        $viewer = Member::factory()->create();
        $this->unifiedOn();

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(function ($page) use ($viewer) {
                $page->component('unified/home')
                    ->has('recentPhotos')
                    ->has('recentDiaries')
                    ->has('groups')
                    ->has('friends')
                    ->where('profile.id', $viewer->getKey())
                    ->where('profile.name', $viewer->name)
                    ->where('profile.avatarUrl', null)
                    ->where('profile.avatarUrlLarge', null)
                    ->where('profile.isAi', false)
                    // The counts the header used to carry are gone with the row that showed them, so
                    // the four queries behind them stop running too.
                    ->missing('profile.stats');

                // Their absence is the contract, not an oversight: the counts those sections carried
                // are on the action tiles' badges instead.
                foreach (self::DASHBOARD_PROPS as $prop) {
                    $page->missing($prop);
                }

                return $page;
            });
    }

    /**
     * The look reaches the shell as well as the page: the mobile bars read one shared prop rather
     * than resolving it again per bar. Switching the site adds no key to the response — what it must
     * not change is what the chrome renders while the look is standard.
     */
    public function test_the_shell_learns_the_look_from_a_shared_prop(): void
    {
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('look', 'standard'));

        $this->unifiedOn();

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('look', 'unified'));
    }

    public function test_a_guest_is_never_given_the_unified_chrome(): void
    {
        // A look is a member's way around their own pages, and a signed-out visitor reaches none
        // of them — so the site default does not answer for a guest.
        config()->set('openpne.surface_mode', 'modern_only');
        $this->unifiedOn();

        $this->get('/login')->assertInertia(fn ($page) => $page->where('look', 'standard'));
    }

    public function test_a_stored_value_no_look_answers_to_reads_as_standard(): void
    {
        // No row at all: the shipped home, without an operator having said anything.
        $this->assertSame(Look::Standard, app(SnsSettingService::class)->get(SnsSettingKey::DefaultLook));

        foreach (['0', '', '2', 'Unified'] as $stored) {
            DB::table('sns_settings')->updateOrInsert(
                ['key' => SnsSettingKey::DefaultLook->value],
                ['value' => $stored],
            );
            app(SnsSettingService::class)->clearCache();

            $this->assertSame(
                Look::Standard,
                app(SnsSettingService::class)->get(SnsSettingKey::DefaultLook),
                "stored value '{$stored}' resolved to something other than the shipped layout",
            );
        }

        DB::table('sns_settings')->updateOrInsert(
            ['key' => SnsSettingKey::DefaultLook->value],
            ['value' => 'unified'],
        );
        app(SnsSettingService::class)->clearCache();

        $this->assertSame(Look::Unified, app(SnsSettingService::class)->get(SnsSettingKey::DefaultLook));
    }

    public function test_the_viewers_own_private_content_is_on_their_own_home(): void
    {
        // Self clearance is the whole page: the unified home is always the viewer's own, so a
        // self-only diary belongs on it exactly as it does on their archive.
        $viewer = Member::factory()->create();
        $diary = $this->diary($viewer, '2026-08-16 10:00:00', images: 1);
        $post = $this->timelinePost($viewer, '2026-08-16 10:00:01', images: 1);
        $this->unifiedOn();

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('recentDiaries', 1)
                ->where('recentDiaries.0.id', $diary->getKey())
                ->has('recentPhotos', 2)
                ->where('recentPhotos.0.source', 'timeline')
                ->where('recentPhotos.0.href', "/timeline/{$post->getKey()}")
                ->where('recentPhotos.1.source', 'diary')
                ->where('recentPhotos.1.href', "/diary/{$diary->getKey()}")
            );
    }

    public function test_the_groups_and_people_are_the_viewers_own(): void
    {
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create();
        $this->makeFriends($viewer, $friend);

        $group = Group::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $viewer->getKey()]);
        // A group the viewer has not joined, and a member they are not friends with: neither belongs
        // on a page that is entirely about them.
        Group::factory()->create();
        Member::factory()->create();

        $this->unifiedOn();

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('groups', 1)
                ->where('groups.0.id', $group->getKey())
                ->where('groups.0.name', $group->name)
                ->where('groups.0.href', "/groups/{$group->getKey()}")
                ->has('friends', 1)
                ->where('friends.0.id', $friend->getKey())
                ->where('friends.0.name', $friend->name)
                ->where('friends.0.isAi', false)
                ->where('friends.0.href', "/member/{$friend->getKey()}")
            );
    }

    /**
     * The faces are a decoration, but the order is a rule a member can read off the screen: the newest
     * friendships first, as many as the seat map seats. A tenth would hang a row of one under the
     * formation, and an unordered slice would make "who is around" mean whatever the table returned.
     */
    public function test_the_faces_are_the_nine_newest_friendships(): void
    {
        $viewer = Member::factory()->create();
        // Friendship dates scattered across the order the members were created, so the expected row is
        // neither the members ascending nor descending: only reading the friendship's own timestamp
        // produces it, and an unordered slice (which the table returns by ascending id) fails.
        $friends = [];

        foreach ([5, 11, 3, 8, 1, 9, 6, 2, 10, 4, 7] as $day) {
            $friend = Member::factory()->create();
            $this->makeFriends($viewer, $friend, sprintf('2026-08-%02d 10:00:00', $day));
            $friends[] = $friend;
        }

        $this->unifiedOn();
        $expected = array_map(fn (int $made): int => $friends[$made]->getKey(), [1, 8, 5, 3, 10, 6, 0, 9, 2]);

        $page = $this->actingAs($viewer)->get('/dashboard')->viewData('page');

        $this->assertSame($expected, array_column($page['props']['friends'], 'id'));
    }

    public function test_the_group_unit_off_empties_its_section_without_reading_it(): void
    {
        $viewer = Member::factory()->create();
        $group = Group::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $viewer->getKey()]);

        $this->setSnsSetting(SnsSettingKey::FeatureGroupEnabled, false);
        $this->unifiedOn();

        DB::enableQueryLog();
        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('groups', []));
        $groupQueries = array_filter(
            DB::getQueryLog(),
            fn (array $q): bool => str_contains($q['query'], 'groups') || str_contains($q['query'], 'group_members'),
        );
        DB::disableQueryLog();

        $this->assertSame([], $groupQueries, 'a switched-off unit still read its table');
    }

    public function test_the_friend_unit_off_empties_its_section_without_reading_it(): void
    {
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create();
        $this->makeFriends($viewer, $friend);

        $this->setSnsSetting(SnsSettingKey::FeatureFriendEnabled, false);
        $this->unifiedOn();

        DB::enableQueryLog();
        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('friends', []));
        $friendQueries = array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'friendships'));
        DB::disableQueryLog();

        $this->assertSame([], $friendQueries, 'a switched-off unit still read its table');
    }

    public function test_the_diary_unit_off_empties_its_half_without_reading_it(): void
    {
        $viewer = Member::factory()->create();
        $this->diary($viewer, '2026-08-16 10:00:00', images: 1);
        $post = $this->timelinePost($viewer, '2026-08-16 09:00:00', images: 1);

        $this->setSnsSetting(SnsSettingKey::FeatureDiaryEnabled, false);
        $this->unifiedOn();

        DB::enableQueryLog();
        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn ($page) => $page
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
        $viewer = Member::factory()->create();
        $diary = $this->diary($viewer, '2026-08-16 09:00:00', images: 1);
        $this->timelinePost($viewer, '2026-08-16 10:00:00', images: 1);

        $this->setSnsSetting(SnsSettingKey::FeatureTimelineEnabled, false);
        $this->unifiedOn();

        DB::enableQueryLog();
        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('recentPhotos', 1)
                ->where('recentPhotos.0.source', 'diary')
                ->where('recentPhotos.0.href', "/diary/{$diary->getKey()}")
            );
        $postQueries = array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'timeline_posts'));
        DB::disableQueryLog();

        $this->assertSame([], $postQueries, 'a switched-off unit still read its table');
    }

    public function test_photos_mix_the_two_sources_newest_parent_first(): void
    {
        $viewer = Member::factory()->create();
        $oldest = $this->diary($viewer, '2026-08-16 10:00:00', images: 1);
        $middle = $this->timelinePost($viewer, '2026-08-16 10:00:05', images: 1);
        // Same second as each other, so only the parent id can separate them — and the later row
        // (higher id) is the newer post.
        $tied = $this->diary($viewer, '2026-08-16 10:00:10', images: 1);
        $newest = $this->diary($viewer, '2026-08-16 10:00:10', images: 1);
        $this->unifiedOn();

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('recentPhotos', 4)
                ->where('recentPhotos.0.href', "/diary/{$newest->getKey()}")
                ->where('recentPhotos.1.href', "/diary/{$tied->getKey()}")
                ->where('recentPhotos.2.href', "/timeline/{$middle->getKey()}")
                ->where('recentPhotos.3.href', "/diary/{$oldest->getKey()}")
            );
    }

    public function test_the_grid_caps_at_eight_without_disturbing_the_order(): void
    {
        $viewer = Member::factory()->create();
        // Four pictures on the newest post, then eight older ones with a picture each: twelve in all,
        // so the cap has to cut somewhere and must cut the oldest.
        $rich = $this->diary($viewer, '2026-08-16 10:10:00', images: 4);
        $posts = [];
        foreach (range(1, 8) as $minute) {
            $posts[$minute] = $this->timelinePost($viewer, "2026-08-16 10:0{$minute}:00", images: 1);
        }
        $this->unifiedOn();

        $expected = [
            // The rich parent's own pictures keep the order their author arranged them in.
            ...array_fill(0, 4, "/diary/{$rich->getKey()}"),
            ...array_map(fn (int $minute): string => "/timeline/{$posts[$minute]->getKey()}", [8, 7, 6, 5]),
        ];

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(function ($page) use ($expected) {
                $page->has('recentPhotos', 8);
                foreach ($expected as $i => $href) {
                    $page->where("recentPhotos.{$i}.href", $href);
                }

                return $page;
            });
    }
}
