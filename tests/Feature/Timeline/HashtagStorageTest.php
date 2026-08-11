<?php

namespace Tests\Feature\Timeline;

use App\Features\Timeline\Actions\CreateReply;
use App\Features\Timeline\Actions\CreateTimelinePost;
use App\Features\Timeline\Data\TimelinePostFormData;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hashtags reach timeline_post_tags from the body itself, with no payload involved
 * (HashtagParserTest covers what the body yields). What is worth pinning here is that both write
 * paths index, that mentions and tags coexist on one post, and that the rows are the post's.
 */
class HashtagStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_post_stores_its_hashtags(): void
    {
        $post = $this->createPost(Member::factory()->create(), 'shipped #op4 today #ok');

        $this->assertTags([
            ['tag' => 'op4', 'offset' => 8, 'length' => 4],
            ['tag' => 'ok', 'offset' => 19, 'length' => 3],
        ], $post);
    }

    public function test_a_post_with_no_hashtag_stores_none(): void
    {
        $post = $this->createPost(Member::factory()->create(), 'nothing to see here');

        $this->assertTags([], $post);
    }

    public function test_a_reply_stores_its_hashtags(): void
    {
        [$author, $replier] = Member::factory()->count(2)->create()->all();
        $parent = TimelinePost::factory()->create(['member_id' => $author->getKey()]);

        $reply = app(CreateReply::class)($replier, $parent, 'me too #ok');

        $this->assertTags([['tag' => 'ok', 'offset' => 7, 'length' => 3]], $reply);
    }

    public function test_a_hashtag_inside_a_mention_is_not_indexed(): void
    {
        $author = Member::factory()->create();
        // A display name that carries a marker: the picker's range covers "@dev #ops", so that
        // marker belongs to the handle.
        $dev = Member::factory()->create(['name' => 'dev #ops']);
        $body = 'hi @dev #ops #ok';

        $post = $this->createPost($author, $body, [
            ['member_id' => $dev->getKey(), 'offset' => 3, 'length' => 9],
        ]);

        $this->assertSame(1, $post->fresh()->mentions()->count());
        $this->assertTags([['tag' => 'ok', 'offset' => 13, 'length' => 3]], $post);
    }

    public function test_a_hashtag_survives_a_mention_that_resolution_dropped(): void
    {
        $author = Member::factory()->create();
        $dev = Member::factory()->create(['name' => 'dev #ops']);
        $body = 'hi @dev #ops #ok';
        $mentions = [['member_id' => $dev->getKey(), 'offset' => 3, 'length' => 9]];

        // Renamed after the picker chose them: the mention row is dropped, so its range no longer
        // shields the marker inside it.
        $dev->update(['name' => 'dev']);
        $post = $this->createPost($author, $body, $mentions);

        $this->assertSame(0, $post->fresh()->mentions()->count());
        $this->assertTags([
            ['tag' => 'ops', 'offset' => 8, 'length' => 4],
            ['tag' => 'ok', 'offset' => 13, 'length' => 3],
        ], $post);
    }

    public function test_deleting_the_post_removes_its_hashtags(): void
    {
        $post = $this->createPost(Member::factory()->create(), 'bye #ok');

        $post->delete();

        $this->assertDatabaseCount('timeline_post_tags', 0);
    }

    /** @param  list<array{member_id: int, offset: int, length: int}>  $mentions */
    private function createPost(Member $author, string $body, array $mentions = []): TimelinePost
    {
        return app(CreateTimelinePost::class)($author, new TimelinePostFormData($body, Visibility::Members, $mentions));
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
