<?php

namespace Tests\Feature\Diary;

use App\Models\Diary;
use App\Models\Member;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The OpenPNE 3 window is `created_at >= midnight N days ago`, so the boundary cases sit one second
 * either side of that midnight; the member archive is pinned outside both switches.
 */
class SearchSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Member $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->viewer = Member::factory()->create();
    }

    public function test_switching_search_off_answers_the_screen_with_404(): void
    {
        $this->setSnsSetting(SnsSettingKey::DiarySearchEnabled, false);

        $this->actingAs($this->viewer)->get('/diary/search?keyword=note')->assertNotFound();
        $this->actingAs($this->viewer)->get('/diary/search')->assertNotFound();
    }

    public function test_switching_search_off_drops_the_form_from_the_recent_feed_on_both_surfaces(): void
    {
        $this->setSnsSetting(SnsSettingKey::DiarySearchEnabled, false);

        $this->actingAs($this->viewer)->get(route('diary.list'))
            ->assertOk()
            ->assertDontSee('id="diarySearchFormLine"', false);

        config(['openpne.surface_mode' => 'modern_default']);
        $this->actingAs($this->viewer)->get(route('diary.list'))
            ->assertInertia(fn ($page) => $page->component('diary/feed')->where('searchable', false));
    }

    public function test_the_recent_feed_offers_the_form_while_search_is_on(): void
    {
        $this->actingAs($this->viewer)->get(route('diary.list'))
            ->assertOk()
            ->assertSee('id="diarySearchFormLine"', false);
    }

    public function test_the_period_window_starts_at_midnight_n_days_ago(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 6, 15, 12, 0, 0));
        $this->setSnsSetting(SnsSettingKey::DiarySearchPeriodEnabled, true);
        $this->setSnsSetting(SnsSettingKey::DiarySearchPeriodDays, 7);

        $midnight = CarbonImmutable::create(2026, 6, 8, 0, 0, 0);
        Diary::factory()->create(['title' => 'Inside note', 'visibility' => Visibility::Members, 'created_at' => $midnight]);
        Diary::factory()->create(['title' => 'Outside note', 'visibility' => Visibility::Members, 'created_at' => $midnight->subSecond()]);

        $this->actingAs($this->viewer)->get('/diary/search?keyword=note')
            ->assertOk()
            ->assertSee('Inside note')
            ->assertDontSee('Outside note');
    }

    public function test_zero_days_is_today_alone(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 6, 15, 12, 0, 0));
        $this->setSnsSetting(SnsSettingKey::DiarySearchPeriodEnabled, true);
        $this->setSnsSetting(SnsSettingKey::DiarySearchPeriodDays, 0);

        Diary::factory()->create(['title' => 'Today note', 'visibility' => Visibility::Members, 'created_at' => CarbonImmutable::create(2026, 6, 15, 0, 0, 0)]);
        Diary::factory()->create(['title' => 'Yesterday note', 'visibility' => Visibility::Members, 'created_at' => CarbonImmutable::create(2026, 6, 14, 23, 59, 59)]);

        $this->actingAs($this->viewer)->get('/diary/search?keyword=note')
            ->assertOk()
            ->assertSee('Today note')
            ->assertDontSee('Yesterday note');
    }

    public function test_the_window_is_off_until_its_switch_is_on(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 6, 15, 12, 0, 0));
        $this->setSnsSetting(SnsSettingKey::DiarySearchPeriodDays, 0);

        Diary::factory()->create(['title' => 'Old note', 'visibility' => Visibility::Members, 'created_at' => CarbonImmutable::create(2020, 1, 1)]);

        $this->actingAs($this->viewer)->get('/diary/search?keyword=note')->assertOk()->assertSee('Old note');
    }

    public function test_neither_switch_reaches_the_member_archive_keyword_filter(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 6, 15, 12, 0, 0));
        $this->setSnsSetting(SnsSettingKey::DiarySearchEnabled, false);
        $this->setSnsSetting(SnsSettingKey::DiarySearchPeriodEnabled, true);
        $this->setSnsSetting(SnsSettingKey::DiarySearchPeriodDays, 0);
        $author = Member::factory()->create();
        Diary::factory()->create(['member_id' => $author->getKey(), 'title' => 'Old note', 'visibility' => Visibility::Members, 'created_at' => CarbonImmutable::create(2020, 1, 1)]);

        config(['openpne.surface_mode' => 'modern_default']);
        $this->actingAs($this->viewer)->get(route('diary.list_member', ['member' => $author->getKey(), 'keyword' => 'note']))
            ->assertInertia(fn ($page) => $page->component('diary/list')->has('diaries.data', 1)->where('diaries.data.0.title', 'Old note'));
    }
}
