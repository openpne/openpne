<?php

namespace Tests\Feature\Support\Stream;

use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Stream\StreamCursor;
use App\Support\Stream\StreamProps;
use App\Support\Stream\StreamQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Support\PinsOrderBy;
use Tests\TestCase;

class StreamQueryTest extends TestCase
{
    use PinsOrderBy;
    use RefreshDatabase;

    private const PER_PAGE = 20;

    private const SECOND = '2026-03-01 12:00:00';

    private Member $author;

    /** Twenty-one rows in one second, so the boundary falls inside a tie, plus one older row whose id is the largest. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->author = Member::factory()->create();
        foreach (range(1, 21) as $id) {
            $this->makePost($id, self::SECOND);
        }
        $this->makePost(100, '2026-02-01 00:00:00');
    }

    public function test_the_head_page_is_newest_first_and_names_its_last_row_as_the_older_cursor(): void
    {
        $page = StreamQuery::older(TimelinePost::query(), null, self::PER_PAGE);

        $this->assertSame(range(21, 2), $page->rows->modelKeys());
        $this->assertTrue($page->hasOlder);
        $this->assertSame(2, $page->olderCursor()->id);
    }

    public function test_the_next_page_continues_through_the_tie_then_by_time_and_exhausts(): void
    {
        $head = StreamQuery::older(TimelinePost::query(), null, self::PER_PAGE);

        $next = StreamQuery::older(TimelinePost::query(), StreamCursor::tryParse((string) $head->olderCursor()), self::PER_PAGE);

        $this->assertSame([1, 100], $next->rows->modelKeys());
        $this->assertFalse($next->hasOlder);
        $this->assertNull($next->olderCursor());
    }

    public function test_a_row_posted_meanwhile_does_not_shift_the_next_page(): void
    {
        $cursor = StreamQuery::older(TimelinePost::query(), null, self::PER_PAGE)->olderCursor();
        $this->makePost(200, '2026-03-02 00:00:00');

        $next = StreamQuery::older(TimelinePost::query(), $cursor, self::PER_PAGE);

        $this->assertSame([1, 100], $next->rows->modelKeys());
    }

    public function test_a_deleted_boundary_row_still_resolves_the_next_page(): void
    {
        $cursor = StreamQuery::older(TimelinePost::query(), null, self::PER_PAGE)->olderCursor();
        TimelinePost::query()->whereKey(2)->delete();

        $this->assertSame([1, 100], StreamQuery::older(TimelinePost::query(), $cursor, self::PER_PAGE)->rows->modelKeys());
    }

    public function test_a_row_without_a_time_is_not_in_the_stream(): void
    {
        DB::table('timeline_posts')->insert(['id' => 300, 'member_id' => $this->author->getKey(), 'body' => 'x', 'visibility' => 1, 'created_at' => null, 'updated_at' => null]);
        TimelinePost::query()->whereKey(range(3, 21))->delete();

        $page = StreamQuery::older(TimelinePost::query(), null, self::PER_PAGE);

        $this->assertSame([2, 1, 100], $page->rows->modelKeys());
        $this->assertFalse($page->hasOlder);
    }

    public function test_a_page_holds_at_least_one_row(): void
    {
        $this->expectException(InvalidArgumentException::class);

        StreamQuery::older(TimelinePost::query(), null, 0);
    }

    public function test_a_page_that_is_exactly_full_has_nothing_older(): void
    {
        TimelinePost::query()->whereKey([1, 100])->delete();

        $page = StreamQuery::older(TimelinePost::query(), null, self::PER_PAGE);

        $this->assertCount(20, $page->rows);
        $this->assertFalse($page->hasOlder);
    }

    public function test_the_sql_orders_by_the_qualified_tuple_and_slices_with_the_or_form(): void
    {
        $cursor = new StreamCursor(now()->toImmutable(), 5);

        $sql = $this->querySqlOn('timeline_posts', fn () => StreamQuery::older(TimelinePost::query()->latest(), $cursor, self::PER_PAGE));
        $order = $this->orderClausesOn('timeline_posts', fn () => StreamQuery::older(TimelinePost::query()->latest(), $cursor, self::PER_PAGE));

        $this->assertCount(1, $sql);
        $this->assertStringContainsString('where timeline_posts.created_at is not null and (timeline_posts.created_at < ? or (timeline_posts.created_at = ? and timeline_posts.id < ?))', $sql[0]);
        $this->assertStringEndsWith(' limit 21', $sql[0]);
        $this->assertSame(['order by timeline_posts.created_at desc, timeline_posts.id desc'], $order);
    }

    public function test_a_relation_with_uuid_keys_pages_by_the_same_tuple(): void
    {
        $ids = collect(['aaaaaaaa-0000-4000-8000-000000000000', 'bbbbbbbb-0000-4000-8000-000000000000', 'cccccccc-0000-4000-8000-000000000000']);
        DB::table('notifications')->insert($ids->map(fn (string $id) => [
            'id' => $id, 'type' => 'x', 'notifiable_type' => $this->author->getMorphClass(), 'notifiable_id' => $this->author->getKey(),
            'data' => '{}', 'created_at' => self::SECOND, 'updated_at' => self::SECOND,
        ])->all());

        $head = StreamQuery::older($this->author->notifications(), null, 2);
        $next = StreamQuery::older($this->author->notifications(), StreamCursor::tryParse((string) $head->olderCursor()), 2);

        $this->assertSame([$ids[2], $ids[1]], $head->rows->modelKeys());
        $this->assertSame($ids[1], $head->olderCursor()->id);
        $this->assertSame([$ids[0]], $next->rows->modelKeys());
        $this->assertFalse($next->hasOlder);
    }

    public function test_the_scroll_prop_carries_the_cursor_in_its_metadata_only(): void
    {
        $head = StreamQuery::older(TimelinePost::query(), null, self::PER_PAGE);
        $prop = StreamProps::scroll($head, fn (TimelinePost $post) => $post->getKey(), null);

        $this->assertSame(['data' => range(21, 2)], $prop());
        $this->assertSame(['pageName' => 'before', 'previousPage' => null, 'nextPage' => (string) $head->olderCursor(), 'currentPage' => null], $prop->metadata());

        $next = StreamQuery::older(TimelinePost::query(), $head->olderCursor(), self::PER_PAGE);
        $metadata = StreamProps::scroll($next, fn (TimelinePost $post) => $post->getKey(), $head->olderCursor())->metadata();

        $this->assertSame((string) $head->olderCursor(), $metadata['currentPage']);
        $this->assertNull($metadata['nextPage']);
    }

    private function makePost(int $id, string $at): void
    {
        TimelinePost::factory()->for($this->author, 'member')->create(['id' => $id, 'created_at' => $at, 'updated_at' => $at]);
    }
}
