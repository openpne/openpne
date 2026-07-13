<?php

namespace Tests\Feature\Diary;

use App\Features\Diary\Serializers\DiarySerializer;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\DiaryImage;
use App\Models\File;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DiarySerializerTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_loads_the_comment_count_when_not_eager_loaded(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        DiaryComment::factory()->for($diary)->create(['number' => 1]);
        DiaryComment::factory()->for($diary)->create(['number' => 2]);

        // A route-bound diary carries no withCount('comments'); summary() must still report the count.
        $fresh = Diary::findOrFail($diary->getKey());

        $this->assertSame(2, DiarySerializer::summary($fresh)['commentCount']);
    }

    public function test_detail_carries_the_comment_count(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        DiaryComment::factory()->for($diary)->create(['number' => 1]);

        // detail() is a superset of summary() (DiaryDetail extends DiarySummary), so it must expose
        // commentCount too; a route-bound diary lazy-loads it.
        $fresh = Diary::findOrFail($diary->getKey());

        $this->assertSame(1, DiarySerializer::detail($fresh)['commentCount']);
    }

    public function test_summary_excerpt_collapses_newlines_to_a_single_line(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey(), 'body' => "First line\nSecond line"]);

        $this->assertSame('First line Second line', DiarySerializer::summary($diary)['excerpt']);
    }

    public function test_summary_excerpt_is_cut_to_display_width_108(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey(), 'body' => str_repeat('a', 200)]);

        // OpenPNE 3's op_truncate width-108 with no ellipsis.
        $this->assertSame(str_repeat('a', 108), DiarySerializer::summary($diary)['excerpt']);
    }

    public function test_summary_thumbnail_is_the_lowest_numbered_image_when_first_image_is_loaded(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        $second = File::factory()->create();
        $first = File::factory()->create();
        DiaryImage::factory()->create(['diary_id' => $diary->getKey(), 'file_id' => $second->getKey(), 'number' => 2]);
        DiaryImage::factory()->create(['diary_id' => $diary->getKey(), 'file_id' => $first->getKey(), 'number' => 1]);

        $loaded = Diary::with('firstImage.file')->findOrFail($diary->getKey());

        $this->assertSame(
            $first->thumbnailUrl(120, 120, square: true),
            DiarySerializer::summary($loaded)['thumbnailUrl'],
        );
    }

    public function test_summary_thumbnail_is_null_and_runs_no_query_when_first_image_is_not_loaded(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        DiaryImage::factory()->create(['diary_id' => $diary->getKey(), 'number' => 1]);

        // Everything summary() reads except the thumbnail source, exactly as a list query provides it.
        $loaded = Diary::with('member.avatar.file')->withCount(['comments', 'images'])->findOrFail($diary->getKey());

        DB::enableQueryLog();
        $summary = DiarySerializer::summary($loaded);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertNull($summary['thumbnailUrl']);
        $this->assertSame([], $queries, 'summary() lazy-loaded firstImage instead of returning null');
    }

    public function test_detail_carries_excerpt_and_thumbnail_from_loaded_images(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey(), 'body' => "Body\ntext"]);
        $second = File::factory()->create();
        $first = File::factory()->create();
        DiaryImage::factory()->create(['diary_id' => $diary->getKey(), 'file_id' => $second->getKey(), 'number' => 2]);
        DiaryImage::factory()->create(['diary_id' => $diary->getKey(), 'file_id' => $first->getKey(), 'number' => 1]);

        // ShowDiary eager-loads images.file (not firstImage); detail() derives the thumbnail from them.
        $loaded = Diary::with('images.file')->findOrFail($diary->getKey());
        $detail = DiarySerializer::detail($loaded);

        $this->assertSame('Body text', $detail['excerpt']);
        $this->assertSame($first->thumbnailUrl(120, 120, square: true), $detail['thumbnailUrl']);
    }

    public function test_detail_thumbnail_is_null_without_images(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);

        $loaded = Diary::with('images.file')->findOrFail($diary->getKey());

        $this->assertNull(DiarySerializer::detail($loaded)['thumbnailUrl']);
    }
}
