<?php

namespace Tests\Feature\Timeline;

use App\Models\Member;
use App\Models\TimelinePost;
use App\Models\TimelinePostMention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The backfill's job is the bodies the parser never saw, so every post here is made by the factory,
 * which writes a row without going through the write path.
 */
class BackfillTimelineHashtagsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_indexes_a_post_that_was_never_parsed(): void
    {
        $post = TimelinePost::factory()->create(['body' => 'shipped #op4 today']);

        $this->backfill();

        $this->assertTags([['tag' => 'op4', 'offset' => 8, 'length' => 4]], $post);
    }

    public function test_running_it_twice_leaves_one_row_per_tag(): void
    {
        $post = TimelinePost::factory()->create(['body' => 'shipped #op4 today']);

        $this->backfill();
        $this->backfill();

        $this->assertTags([['tag' => 'op4', 'offset' => 8, 'length' => 4]], $post);
    }

    public function test_it_replaces_rows_that_no_longer_match_the_body(): void
    {
        $post = TimelinePost::factory()->create(['body' => 'shipped #op4 today']);
        $post->tags()->create(['tag' => 'stale', 'offset' => 0, 'length' => 6]);

        $this->backfill();

        $this->assertTags([['tag' => 'op4', 'offset' => 8, 'length' => 4]], $post);
    }

    public function test_it_leaves_a_marker_that_a_mention_covers_alone(): void
    {
        $member = Member::factory()->create(['name' => 'dev #ops']);
        $post = TimelinePost::factory()->create(['body' => 'hi @dev #ops #ok']);
        TimelinePostMention::create([
            'timeline_post_id' => $post->getKey(),
            'member_id' => $member->getKey(),
            'offset' => 3,
            'length' => 9,
        ]);

        $this->backfill();

        $this->assertTags([['tag' => 'ok', 'offset' => 13, 'length' => 3]], $post);
    }

    public function test_it_reports_what_it_indexed(): void
    {
        TimelinePost::factory()->create(['body' => 'shipped #op4 and #ok']);
        TimelinePost::factory()->create(['body' => 'nothing here']);

        $this->artisan('openpne:timeline-backfill-hashtags')
            ->expectsOutputToContain('Indexed 2 hashtag(s) across 2 post(s).')
            ->assertSuccessful();
    }

    private function backfill(): void
    {
        $this->artisan('openpne:timeline-backfill-hashtags')->assertSuccessful();
    }

    /** @param  list<array{tag: string, offset: int, length: int}>  $expected */
    private function assertTags(array $expected, TimelinePost $post): void
    {
        $stored = $post->fresh()->tags
            ->map(fn ($tag): array => [
                'tag' => $tag->tag,
                'offset' => $tag->offset,
                'length' => $tag->length,
            ])
            ->all();

        $this->assertSame($expected, $stored);
    }
}
