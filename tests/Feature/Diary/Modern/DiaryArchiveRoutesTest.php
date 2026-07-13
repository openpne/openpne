<?php

namespace Tests\Feature\Diary\Modern;

use App\Models\Diary;
use App\Models\DiaryImage;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DiaryArchiveRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openpne.surface_mode' => 'modern_default']);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/diary/listMember/1/2026/3')->assertRedirect('/login');
    }

    public function test_month_archive_renders_inertia_with_period_and_filtered_data(): void
    {
        $owner = Member::factory()->create();
        Diary::factory()->create([
            'member_id' => $owner->getKey(), 'title' => 'March entry',
            'visibility' => Visibility::Members, 'created_at' => '2026-03-10 09:00:00',
        ]);
        Diary::factory()->create([
            'member_id' => $owner->getKey(), 'title' => 'April entry',
            'visibility' => Visibility::Members, 'created_at' => '2026-04-02 09:00:00',
        ]);

        $this->actingAs($owner)->get("/diary/listMember/{$owner->getKey()}/2026/3")
            ->assertInertia(fn ($page) => $page
                ->component('diary/list')
                ->where('period', '2026-03')
                ->has('diaries.data', 1)
                ->where('diaries.data.0.title', 'March entry')
            );
    }

    public function test_archive_rich_row_carries_thumbnails(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create([
            'member_id' => $owner->getKey(), 'title' => 'March entry',
            'visibility' => Visibility::Members, 'created_at' => '2026-03-10 09:00:00',
        ]);
        DiaryImage::factory()->create(['diary_id' => $diary->getKey(), 'number' => 1]);

        $this->actingAs($owner)->get("/diary/listMember/{$owner->getKey()}/2026/3")
            ->assertInertia(fn ($page) => $page
                ->has('diaries.data.0.thumbnails', 1)
                ->where('diaries.data.0.thumbnails.0', fn ($url) => is_string($url) && $url !== '')
            );
    }

    public function test_thumbnail_eager_load_runs_a_single_batched_images_query(): void
    {
        $owner = Member::factory()->create();
        foreach (range(1, 6) as $i) {
            $diary = Diary::factory()->create([
                'member_id' => $owner->getKey(),
                'visibility' => Visibility::Members,
                'created_at' => '2026-03-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).' 09:00:00',
            ]);
            DiaryImage::factory()->create(['diary_id' => $diary->getKey(), 'number' => 1]);
        }

        DB::enableQueryLog();
        $this->actingAs($owner)->get("/diary/listMember/{$owner->getKey()}/2026/3")->assertOk();
        // loadMissing('images.file') batches all six rows into one standalone diary_images load
        // keyed by `diary_id in (...)`, not one per row. The correlated images_count subquery on the
        // diaries page reads `diaries.id = diary_images.diary_id`, so this marker excludes it. Strip
        // identifier quotes first so it matches on both sqlite ("…") and MySQL (`…`).
        $imageQueries = array_filter(
            array_column(DB::getQueryLog(), 'query'),
            fn (string $query): bool => str_contains(str_replace(['"', '`'], '', $query), 'from diary_images where diary_images.diary_id in'),
        );
        DB::disableQueryLog();

        $this->assertCount(1, $imageQueries);
    }

    public function test_impossible_date_returns_404(): void
    {
        $owner = Member::factory()->create();

        $this->actingAs($owner)->get("/diary/listMember/{$owner->getKey()}/2026/2/30")->assertNotFound();
    }

    public function test_month_archive_carries_monthly_counts_and_highlights_the_month(): void
    {
        $owner = Member::factory()->create();
        Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members, 'created_at' => '2026-03-10 09:00:00']);
        Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members, 'created_at' => '2026-02-01 09:00:00']);

        $this->actingAs($owner)->get("/diary/listMember/{$owner->getKey()}/2026/3")
            ->assertInertia(fn ($page) => $page
                ->component('diary/list')
                ->where('archive.year', 2026)
                ->where('archive.month', 3)
                ->has('monthlyCounts', 2)
                ->where('monthlyCounts.0.year', 2026)
                ->where('monthlyCounts.0.month', 3)
                ->where('monthlyCounts.0.count', 1)
                ->where('monthlyCounts.1.month', 2)
            );
    }

    public function test_list_member_has_monthly_counts_and_null_archive(): void
    {
        $owner = Member::factory()->create();
        Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members, 'created_at' => '2026-03-10 09:00:00']);

        $this->actingAs($owner)->get("/diary/listMember/{$owner->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->component('diary/list')
                ->where('archive', null)
                ->has('monthlyCounts', 1)
                ->where('monthlyCounts.0.year', 2026)
                ->where('monthlyCounts.0.month', 3)
                ->where('monthlyCounts.0.count', 1)
            );
    }

    public function test_monthly_counts_are_viewer_scoped(): void
    {
        [$owner, $viewer] = Member::factory()->count(2)->create()->all();
        Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members, 'created_at' => '2026-03-10 09:00:00']);
        Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Private, 'created_at' => '2026-03-20 09:00:00']);

        // A non-friend viewer's grid counts only the Members-level entry, not the private one.
        $this->actingAs($viewer)->get("/diary/listMember/{$owner->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->has('monthlyCounts', 1)
                ->where('monthlyCounts.0.count', 1)
            );
    }
}
