<?php

namespace Tests\Feature\Diary\Classic;

use App\Models\Diary;
use App\Models\DiaryImage;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The rich Modern rows eager-load a diary's images inside the Modern closure only. Classic must stay
 * at zero added cost: it never runs the standalone diary_images load (nor its follow-up files query),
 * and its query count does not grow with the number of image-bearing entries.
 */
class DiaryRichRowCostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openpne.surface_mode' => 'classic_default']);
    }

    /** @return list<string> */
    private function queriesFor(Member $viewer, string $uri): array
    {
        DB::enableQueryLog();
        $this->actingAs($viewer)->get($uri)->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return $queries;
    }

    private function attachImage(Diary $diary): void
    {
        DiaryImage::factory()->create(['diary_id' => $diary->getKey(), 'number' => 1]);
    }

    public function test_classic_feed_never_runs_the_modern_images_eager_load(): void
    {
        $viewer = Member::factory()->create();
        foreach (Member::factory()->count(3)->create() as $author) {
            $this->attachImage(Diary::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members]));
        }

        foreach ($this->queriesFor($viewer, '/diary/list') as $query) {
            // loadMissing('images.file') runs a standalone `diary_images ... where diary_id in (...)`
            // load; the withCount images subquery instead reads `diaries.id = diary_images.diary_id`.
            // The marker's absence proves Classic ran no images eager load. Strip identifier quotes so
            // it matches on both sqlite and MySQL.
            $this->assertStringNotContainsString('from diary_images where diary_images.diary_id in', str_replace(['"', '`'], '', $query));
        }
    }

    public function test_classic_archive_never_runs_the_modern_images_eager_load(): void
    {
        $owner = Member::factory()->create();
        foreach (range(1, 3) as $i) {
            $this->attachImage(Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members]));
        }

        foreach ($this->queriesFor($owner, "/diary/listMember/{$owner->getKey()}") as $query) {
            $this->assertStringNotContainsString('from diary_images where diary_images.diary_id in', str_replace(['"', '`'], '', $query));
        }
    }

    public function test_classic_list_member_never_runs_the_modern_monthly_counts_query(): void
    {
        $owner = Member::factory()->create();
        foreach (range(1, 3) as $i) {
            $this->attachImage(Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members]));
        }

        foreach ($this->queriesFor($owner, "/diary/listMember/{$owner->getKey()}") as $query) {
            // The Modern archive grid's per-month counts query groups by the year-month alias; it is
            // resolved inside the Modern closure only, so Classic must never emit it. Strip identifier
            // quotes so the marker matches on both sqlite and MySQL.
            $this->assertStringNotContainsString('group by ym', str_replace(['"', '`'], '', $query));
        }
    }
}
