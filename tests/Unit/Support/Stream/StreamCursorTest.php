<?php

namespace Tests\Unit\Support\Stream;

use App\Models\TimelinePost;
use App\Support\Stream\StreamCursor;
use Carbon\CarbonImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StreamCursorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Asia/Tokyo']);
        date_default_timezone_set('Asia/Tokyo');
    }

    public function test_an_integer_key_round_trips(): void
    {
        $post = (new TimelinePost)->forceFill(['id' => 42, 'created_at' => '2026-03-01 12:34:56']);

        $cursor = StreamCursor::of($post);
        $parsed = StreamCursor::tryParse((string) $cursor);

        $this->assertSame(42, $parsed->id);
        $this->assertTrue($parsed->at->equalTo(CarbonImmutable::parse('2026-03-01 12:34:56')));
        $this->assertSame((string) $cursor, (string) $parsed);
    }

    public function test_a_uuid_key_round_trips_as_a_string(): void
    {
        $parsed = StreamCursor::tryParse('2026-03-01T12:34:56+09:00|9b2f1c3e-4d5a-4b6c-8d7e-0f1a2b3c4d5e');

        $this->assertSame('9b2f1c3e-4d5a-4b6c-8d7e-0f1a2b3c4d5e', $parsed->id);
    }

    public function test_a_cursor_from_another_offset_is_normalized_to_the_site_timezone(): void
    {
        $parsed = StreamCursor::tryParse('2026-03-01T03:34:56+00:00|1');

        $this->assertSame('Asia/Tokyo', $parsed->at->timezoneName);
        $this->assertSame('2026-03-01T12:34:56+09:00|1', (string) $parsed);
    }

    #[DataProvider('malformed')]
    public function test_a_malformed_cursor_parses_to_null(mixed $value): void
    {
        $this->assertNull(StreamCursor::tryParse($value));
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformed(): iterable
    {
        yield 'absent' => [null];
        yield 'array query' => [['2026-03-01T12:34:56+09:00|1']];
        yield 'no separator' => ['2026-03-01T12:34:56+09:00'];
        yield 'negative id' => ['2026-03-01T12:34:56+09:00|-1'];
        yield 'word id' => ['2026-03-01T12:34:56+09:00|abc'];
        yield 'not a time' => ['yesterday-ish|1'];
        yield 'empty id' => ['2026-03-01T12:34:56+09:00|'];
        yield 'empty time' => ['|5'];
        yield 'year only' => ['2026|5'];
        yield 'space-separated time' => ['2026-03-01 12:34:56|5'];
        yield 'trailing junk' => ['2026-03-01T12:34:56+09:00x|5'];
    }

    public function test_a_column_the_model_does_not_cast_is_parsed_as_the_engine_wrote_it(): void
    {
        $post = (new TimelinePost)->setRawAttributes(['id' => 3, 'bumped_at' => '2026-03-01 12:34:56']);

        $cursor = StreamCursor::of($post, 'bumped_at');

        $this->assertSame('2026-03-01T12:34:56+09:00|3', (string) $cursor);
    }

    public function test_a_row_without_a_time_has_no_cursor(): void
    {
        $post = new TimelinePost;
        $post->id = 7;

        $this->expectException(LogicException::class);

        StreamCursor::of($post);
    }
}
