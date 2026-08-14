<?php

namespace Tests\Feature\Diary;

use App\Features\Diary\Serializers\DiarySerializer;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\DiaryImage;
use App\Models\File;
use App\Models\Member;
use App\Support\BodyFormat;
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

    public function test_summary_thumbnails_are_the_number_ordered_urls_when_images_are_loaded(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        $second = File::factory()->create();
        $first = File::factory()->create();
        DiaryImage::factory()->create(['diary_id' => $diary->getKey(), 'file_id' => $second->getKey(), 'number' => 2]);
        DiaryImage::factory()->create(['diary_id' => $diary->getKey(), 'file_id' => $first->getKey(), 'number' => 1]);

        $loaded = Diary::with('images.file')->findOrFail($diary->getKey());

        $this->assertSame(
            [$first->thumbnailUrl(120, 120, square: true), $second->thumbnailUrl(120, 120, square: true)],
            DiarySerializer::summary($loaded)['thumbnails'],
        );
    }

    public function test_summary_thumbnails_are_empty_and_run_no_query_when_images_are_not_loaded(): void
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

        $this->assertSame([], $summary['thumbnails']);
        $this->assertSame([], $queries, 'summary() lazy-loaded images instead of returning []');
    }

    public function test_detail_carries_excerpt_and_thumbnails_from_loaded_images(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey(), 'body' => "Body\ntext"]);
        $second = File::factory()->create();
        $first = File::factory()->create();
        DiaryImage::factory()->create(['diary_id' => $diary->getKey(), 'file_id' => $second->getKey(), 'number' => 2]);
        DiaryImage::factory()->create(['diary_id' => $diary->getKey(), 'file_id' => $first->getKey(), 'number' => 1]);

        // ShowDiary eager-loads images.file; detail() derives the thumbnails from them, number-ordered.
        $loaded = Diary::with('images.file')->findOrFail($diary->getKey());
        $detail = DiarySerializer::detail($loaded);

        $this->assertSame('Body text', $detail['excerpt']);
        $this->assertSame(
            [$first->thumbnailUrl(120, 120, square: true), $second->thumbnailUrl(120, 120, square: true)],
            $detail['thumbnails'],
        );
    }

    public function test_detail_images_gained_the_fit_sources_without_moving_the_thumbnail_list(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        $file = File::factory()->create(['type' => 'image/png', 'width' => 1600, 'height' => 900]);
        DiaryImage::factory()->create(['diary_id' => $diary->getKey(), 'file_id' => $file->getKey(), 'number' => 1]);

        $detail = DiarySerializer::detail(Diary::with('images.file')->findOrFail($diary->getKey()));

        $this->assertSame($file->thumbnailUrl(640, 640), $detail['images'][0]['fitSources'][1]['url']);
        $this->assertSame(1600, $detail['images'][0]['width']);
        // thumbnails is derived from the same entries and stays the 120px square list it was.
        $this->assertSame([$file->thumbnailUrl(120, 120, square: true)], $detail['thumbnails']);
    }

    public function test_detail_thumbnails_are_empty_without_images(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);

        $loaded = Diary::with('images.file')->findOrFail($diary->getKey());

        $this->assertSame([], DiarySerializer::detail($loaded)['thumbnails']);
    }

    public function test_detail_body_html_is_null_for_a_plain_body(): void
    {
        $owner = Member::factory()->create();
        Diary::factory()->create(['id' => 1, 'member_id' => $owner->getKey(), 'body' => '<op:b>x</op:b>']);

        $detail = DiarySerializer::detail(Diary::with('images.file')->findOrFail(1));

        $this->assertSame('plain', $detail['format']);
        $this->assertNull($detail['bodyHtml']);
    }

    public function test_detail_body_html_is_populated_for_an_op3_body(): void
    {
        $owner = Member::factory()->create();
        Diary::factory()->create(['id' => 1, 'member_id' => $owner->getKey(), 'format' => BodyFormat::Op3, 'body' => '<op:b>x</op:b>']);

        $detail = DiarySerializer::detail(Diary::with('images.file')->findOrFail(1));

        $this->assertSame('op3', $detail['format']);
        $this->assertSame('<span class="op_b">x</span>', $detail['bodyHtml']);
    }
}
