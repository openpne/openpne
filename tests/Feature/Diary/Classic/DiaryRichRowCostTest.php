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
 * Classic must stay at zero added cost for the rich Modern rows: it never runs the standalone
 * diary_images load, and its query count does not grow with the number of image-bearing entries.
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
    private function queriesFor(?Member $viewer, string $uri): array
    {
        DB::flushQueryLog(); // the log survives disableQueryLog(), so a second call would stack
        DB::enableQueryLog();
        ($viewer === null ? $this : $this->actingAs($viewer))->get($uri)->assertOk();
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
            // The standalone `diary_images ... where diary_id in (...)` load is the marker — the
            // withCount subquery reads `diaries.id = diary_images.diary_id` instead — with the
            // identifier quotes stripped so it matches on both sqlite and MySQL.
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

    public function test_the_guest_feed_and_archive_cost_does_not_grow_with_the_row_count(): void
    {
        // The guest path resolves its author per row, so an eager-load lost on the way to the
        // web-public tier shows up as a query per entry — compared row-count to row-count, never
        // against a pinned absolute.
        $author = Member::factory()->create();
        $entries = fn (int $n) => collect(range(1, $n))->each(fn () => $this->attachImage(
            Diary::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Open]),
        ));

        $archive = "/diary/listMember/{$author->getKey()}";

        $entries(1);
        // Warm the per-process caches (settings, terms, navigation) so the baseline measures the
        // page, not the first request in the process.
        $this->queriesFor(null, '/diary/list');
        $this->queriesFor(null, $archive);
        $one = count($this->queriesFor(null, '/diary/list'));
        $oneArchive = count($this->queriesFor(null, $archive));

        $entries(4);
        $this->assertSame($one, count($this->queriesFor(null, '/diary/list')));
        $this->assertSame($oneArchive, count($this->queriesFor(null, $archive)));
    }

    public function test_classic_list_member_never_runs_the_modern_monthly_counts_query(): void
    {
        $owner = Member::factory()->create();
        foreach (range(1, 3) as $i) {
            $this->attachImage(Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members]));
        }

        foreach ($this->queriesFor($owner, "/diary/listMember/{$owner->getKey()}") as $query) {
            // The Modern archive grid's per-month counts group by the year-month alias, which Classic
            // must never emit, with the identifier quotes stripped so the marker matches on both
            // engines.
            $this->assertStringNotContainsString('group by ym', str_replace(['"', '`'], '', $query));
        }
    }
}
