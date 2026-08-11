<?php

namespace Tests\Feature\Timeline\Queries;

use App\Features\Timeline\Actions\CreateReply;
use App\Features\Timeline\Actions\CreateTimelinePost;
use App\Features\Timeline\Data\TimelinePostFormData;
use App\Features\Timeline\Queries\TagFeed;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The tag page's feed. Two things to pin: the lookup speaks the parser's normalized form (the column
 * is byte-equal, so a query that does not normalize finds nothing), and collecting posts under a tag
 * never widens who may read them — the audience is HomeFeed's, unchanged.
 */
class TagFeedTest extends TestCase
{
    use RefreshDatabase;

    // Normalized lookup ---------------------------------------------------------

    public function test_an_upper_case_term_finds_the_lower_case_stored_tag(): void
    {
        $viewer = Member::factory()->create();
        $post = $this->createPost($viewer, 'shipped #tag today');

        $this->assertSame([$post->getKey()], $this->feedIds($viewer, 'TAG'));
    }

    public function test_a_full_width_term_finds_the_same_topic(): void
    {
        $viewer = Member::factory()->create();
        $post = $this->createPost($viewer, 'shipped #ＴＡＧ today');

        $this->assertSame([$post->getKey()], $this->feedIds($viewer, 'tag'));
        $this->assertSame([$post->getKey()], $this->feedIds($viewer, 'ＴＡＧ'));
    }

    public function test_an_accented_tag_is_a_different_topic(): void
    {
        $viewer = Member::factory()->create();
        $cafe = $this->createPost($viewer, 'a #cafe visit');
        $cafeAccented = $this->createPost($viewer, 'un #café aussi');

        $this->assertSame([$cafe->getKey()], $this->feedIds($viewer, 'cafe'));
        $this->assertSame([$cafeAccented->getKey()], $this->feedIds($viewer, 'café'));
    }

    public function test_a_tag_nobody_used_yields_an_empty_feed(): void
    {
        $viewer = Member::factory()->create();
        $this->createPost($viewer, 'shipped #tag today');

        $this->assertSame([], $this->feedIds($viewer, 'other'));
    }

    // Audience ------------------------------------------------------------------

    public function test_a_strangers_friends_only_post_stays_out_of_the_tag_page(): void
    {
        [$viewer, $friend, $stranger] = Member::factory()->count(3)->create()->all();
        DB::table('friendships')->insert([
            ['member_id' => $viewer->getKey(), 'friend_id' => $friend->getKey()],
            ['member_id' => $friend->getKey(), 'friend_id' => $viewer->getKey()],
        ]);
        $friends = $this->createPost($friend, 'ours #tag', Visibility::Friends);
        $strangers = $this->createPost($stranger, 'theirs #tag', Visibility::Friends);

        $ids = $this->feedIds($viewer, 'tag');
        $this->assertContains($friends->getKey(), $ids);
        $this->assertNotContains($strangers->getKey(), $ids);
    }

    public function test_a_strangers_private_post_stays_out_while_the_viewers_own_appears(): void
    {
        [$viewer, $stranger] = Member::factory()->count(2)->create()->all();
        $own = $this->createPost($viewer, 'mine #tag', Visibility::Private);
        $theirs = $this->createPost($stranger, 'theirs #tag', Visibility::Private);

        $ids = $this->feedIds($viewer, 'tag');
        $this->assertContains($own->getKey(), $ids);
        $this->assertNotContains($theirs->getKey(), $ids);
    }

    public function test_a_post_by_an_author_who_blocks_the_viewer_is_excluded(): void
    {
        [$viewer, $blocker] = Member::factory()->count(2)->create()->all();
        $post = $this->createPost($blocker, 'hello #tag');
        DB::table('member_blocks')->insert(['blocker_id' => $blocker->getKey(), 'blocked_id' => $viewer->getKey()]);

        $this->assertNotContains($post->getKey(), $this->feedIds($viewer, 'tag'));
    }

    // Shape ---------------------------------------------------------------------

    public function test_a_reply_carrying_the_tag_is_not_a_row(): void
    {
        $viewer = Member::factory()->create();
        $root = $this->createPost($viewer, 'no marker here');
        app(CreateReply::class)($viewer, $root, 'me too #tag');

        $this->assertSame([], $this->feedIds($viewer, 'tag'));
    }

    public function test_a_post_appears_once_however_often_it_repeats_the_tag(): void
    {
        $viewer = Member::factory()->create();
        $post = $this->createPost($viewer, '#tag and again #tag');

        $this->assertSame([$post->getKey()], $this->feedIds($viewer, 'tag'));
    }

    public function test_the_feed_is_newest_first_and_paginated(): void
    {
        $viewer = Member::factory()->create();
        $older = $this->createPost($viewer, 'older #tag');
        $newer = $this->createPost($viewer, 'newer #tag');
        TimelinePost::query()->whereKey($older->getKey())->update(['created_at' => '2026-01-01 09:00:00']);

        $feed = (new TagFeed)($viewer, 'tag', perPage: 1);

        $this->assertSame([$newer->getKey()], collect($feed->items())->map->getKey()->all());
        $this->assertSame(2, $feed->total());
    }

    // Helpers -------------------------------------------------------------------

    /** @return list<int> */
    private function feedIds(Member $viewer, string $tag): array
    {
        return collect((new TagFeed)($viewer, $tag)->items())->map->getKey()->all();
    }

    private function createPost(Member $author, string $body, Visibility $visibility = Visibility::Members): TimelinePost
    {
        return app(CreateTimelinePost::class)($author, new TimelinePostFormData($body, $visibility));
    }
}
