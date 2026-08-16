<?php

namespace Tests\Feature\Home;

use App\Features\Home\HomeLayout;
use App\Models\Diary;
use App\Models\DiaryImage;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Models\TimelinePostImage;
use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The experiment switch (SnsSettingKey::ModernUnifiedHome) pins BOTH sides: with it off the previous
 * home is byte-for-byte what it was, and with it on the new page carries only what the same
 * viewer-scoped queries already return.
 */
class UnifiedHomeTest extends TestCase
{
    use RefreshDatabase;

    /** Every prop the digest dashboard ships — the payload the switch must leave alone while off. */
    private const DASHBOARD_PROPS = ['announcements', 'talkRooms', 'diaries', 'timeline', 'groupActivity', 'myDiaries'];

    private function unifiedOn(): void
    {
        $this->setSnsSetting(SnsSettingKey::ModernUnifiedHome, true);
        $this->freshRequestState();
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

                return $page->missing('profile')->missing('recentPhotos')->missing('recentDiaries');
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
                    ->where('profile.id', $viewer->getKey())
                    ->where('profile.name', $viewer->name)
                    ->where('profile.avatarUrl', null)
                    ->where('profile.isAi', false)
                    ->has('profile.stats');

                // Their absence is the contract, not an oversight: the counts those sections carried
                // are on the action tiles' badges instead.
                foreach (self::DASHBOARD_PROPS as $prop) {
                    $page->missing($prop);
                }

                return $page;
            });
    }

    public function test_the_accessor_reads_only_an_explicit_one(): void
    {
        // No row at all: the shipped home, without an operator having said anything.
        $this->assertFalse(HomeLayout::unifiedEnabled());

        foreach (['0', '', '2'] as $stored) {
            DB::table('sns_settings')->updateOrInsert(
                ['key' => SnsSettingKey::ModernUnifiedHome->value],
                ['value' => $stored],
            );
            app(SnsSettingService::class)->clearCache();

            $this->assertFalse(HomeLayout::unifiedEnabled(), "stored value '{$stored}' turned the experiment on");
        }

        DB::table('sns_settings')->updateOrInsert(
            ['key' => SnsSettingKey::ModernUnifiedHome->value],
            ['value' => '1'],
        );
        app(SnsSettingService::class)->clearCache();

        $this->assertTrue(HomeLayout::unifiedEnabled());
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
                ->where('profile.stats.diaries', 1)
                ->where('profile.stats.activity', 1)
            );
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
                ->where('profile.stats.diaries', 0)
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
                ->where('profile.stats.activity', 0)
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

    public function test_the_grid_caps_at_nine_without_disturbing_the_order(): void
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
            ...array_map(fn (int $minute): string => "/timeline/{$posts[$minute]->getKey()}", [8, 7, 6, 5, 4]),
        ];

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(function ($page) use ($expected) {
                $page->has('recentPhotos', 9);
                foreach ($expected as $i => $href) {
                    $page->where("recentPhotos.{$i}.href", $href);
                }

                return $page;
            });
    }
}
