<?php

namespace Tests\Feature\Timeline;

use App\Features\Timeline\Actions\CreateReply;
use App\Features\Timeline\Actions\CreateTimelinePost;
use App\Features\Timeline\Data\TimelinePostFormData;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The storage half of the mention contract; a structurally broken payload never gets this far (MentionRequestTest). */
class MentionStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_stores_the_valid_mentions_in_body_order(): void
    {
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $bob = Member::factory()->create(['name' => 'Bob']);
        $body = 'hi @Alice and @Bob';

        // Submitted last-first: the stored order comes from the offsets, not the payload.
        $post = $this->createPost($author, $body, [$this->at($bob, $body), $this->at($alice, $body)]);

        $this->assertMentions([
            ['member_id' => $alice->getKey(), 'offset' => 3, 'length' => 6],
            ['member_id' => $bob->getKey(), 'offset' => 14, 'length' => 4],
        ], $post);
    }

    public function test_stores_two_mentions_of_the_same_member(): void
    {
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $body = 'hi @Alice and @Alice';

        $post = $this->createPost($author, $body, [
            ['member_id' => $alice->getKey(), 'offset' => 3, 'length' => 6],
            ['member_id' => $alice->getKey(), 'offset' => 14, 'length' => 6],
        ]);

        $this->assertMentions([
            ['member_id' => $alice->getKey(), 'offset' => 3, 'length' => 6],
            ['member_id' => $alice->getKey(), 'offset' => 14, 'length' => 6],
        ], $post);
    }

    public function test_drops_a_mention_running_past_the_end_of_the_body(): void
    {
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $bob = Member::factory()->create(['name' => 'Bob']);
        $body = 'hi @Alice and @Bob'; // 18 code points

        $post = $this->createPost($author, $body, [
            $this->at($alice, $body),
            ['member_id' => $bob->getKey(), 'offset' => 14, 'length' => 10],
        ]);

        $this->assertMentions([['member_id' => $alice->getKey(), 'offset' => 3, 'length' => 6]], $post);
    }

    public function test_drops_a_mention_overlapping_one_already_taken(): void
    {
        $author = Member::factory()->create();
        // A name that contains another member's handle: both ranges read correctly on their own,
        // but the second starts inside the first.
        $aliceBob = Member::factory()->create(['name' => 'Alice @Bob']);
        $bob = Member::factory()->create(['name' => 'Bob']);
        $body = 'hi @Alice @Bob';

        $post = $this->createPost($author, $body, [$this->at($aliceBob, $body), $this->at($bob, $body)]);

        $this->assertMentions([['member_id' => $aliceBob->getKey(), 'offset' => 3, 'length' => 11]], $post);
    }

    public function test_drops_a_mention_of_a_member_renamed_since_it_was_picked(): void
    {
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $bob = Member::factory()->create(['name' => 'Bob']);
        $body = 'hi @Alice and @Bob';
        $mentions = [$this->at($alice, $body), $this->at($bob, $body)];

        $alice->update(['name' => 'Alicia']);
        $post = $this->createPost($author, $body, $mentions);

        $this->assertMentions([['member_id' => $bob->getKey(), 'offset' => 14, 'length' => 4]], $post);
    }

    public function test_drops_a_mention_of_a_member_that_does_not_exist(): void
    {
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $body = 'hi @Alice and @Bob';

        $post = $this->createPost($author, $body, [
            $this->at($alice, $body),
            ['member_id' => $alice->getKey() + 10_000, 'offset' => 14, 'length' => 4],
        ]);

        $this->assertMentions([['member_id' => $alice->getKey(), 'offset' => 3, 'length' => 6]], $post);
    }

    public function test_drops_a_mention_of_a_banned_member(): void
    {
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $bob = Member::factory()->create(['name' => 'Bob']);
        $bob->forceFill(['is_login_rejected' => true])->save();
        $body = 'hi @Alice and @Bob';

        $post = $this->createPost($author, $body, [$this->at($alice, $body), $this->at($bob, $body)]);

        $this->assertMentions([['member_id' => $alice->getKey(), 'offset' => 3, 'length' => 6]], $post);
    }

    public function test_drops_a_mention_of_the_author_themselves(): void
    {
        $author = Member::factory()->create(['name' => 'Author']);
        $alice = Member::factory()->create(['name' => 'Alice']);
        $body = 'hi @Alice and @Author';

        $post = $this->createPost($author, $body, [$this->at($alice, $body), $this->at($author, $body)]);

        $this->assertMentions([['member_id' => $alice->getKey(), 'offset' => 3, 'length' => 6]], $post);
    }

    public function test_drops_a_mention_of_someone_the_author_blocked(): void
    {
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $bob = Member::factory()->create(['name' => 'Bob']);
        $this->block($author, $bob);
        $body = 'hi @Alice and @Bob';

        $post = $this->createPost($author, $body, [$this->at($alice, $body), $this->at($bob, $body)]);

        $this->assertMentions([['member_id' => $alice->getKey(), 'offset' => 3, 'length' => 6]], $post);
    }

    public function test_drops_a_mention_of_someone_who_blocked_the_author(): void
    {
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $bob = Member::factory()->create(['name' => 'Bob']);
        $this->block($bob, $author);
        $body = 'hi @Alice and @Bob';

        $post = $this->createPost($author, $body, [$this->at($alice, $body), $this->at($bob, $body)]);

        $this->assertMentions([['member_id' => $alice->getKey(), 'offset' => 3, 'length' => 6]], $post);
    }

    public function test_a_post_whose_every_mention_drops_is_still_stored(): void
    {
        $author = Member::factory()->create(['name' => 'Author']);
        $body = 'hi @Author';

        $post = $this->createPost($author, $body, [$this->at($author, $body)]);

        $this->assertDatabaseHas('timeline_posts', ['id' => $post->getKey(), 'body' => $body]);
        $this->assertMentions([], $post);
    }

    public function test_a_reply_stores_its_mentions(): void
    {
        [$author, $replier] = Member::factory()->count(2)->create()->all();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $parent = TimelinePost::factory()->create(['member_id' => $author->getKey()]);
        $body = 'hi @Alice';

        $reply = app(CreateReply::class)($replier, $parent, $body, [$this->at($alice, $body)]);

        $this->assertMentions([['member_id' => $alice->getKey(), 'offset' => 3, 'length' => 6]], $reply);
    }

    public function test_deleting_the_post_removes_its_mentions(): void
    {
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $body = 'hi @Alice';
        $post = $this->createPost($author, $body, [$this->at($alice, $body)]);

        $post->delete();

        $this->assertDatabaseCount('timeline_post_mentions', 0);
    }

    public function test_deleting_the_member_removes_their_mentions_and_leaves_the_post(): void
    {
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $body = 'hi @Alice';
        $post = $this->createPost($author, $body, [$this->at($alice, $body)]);

        $alice->delete();

        $this->assertDatabaseCount('timeline_post_mentions', 0);
        // The body keeps the handle as the plain text it always was — nothing has to render a
        // mention whose member is gone.
        $this->assertDatabaseHas('timeline_posts', ['id' => $post->getKey(), 'body' => $body]);
    }

    /** @param  list<array{member_id: int, offset: int, length: int}>  $mentions */
    private function createPost(Member $author, string $body, array $mentions): TimelinePost
    {
        return app(CreateTimelinePost::class)($author, new TimelinePostFormData($body, Visibility::Members, $mentions));
    }

    /** The payload row a picker would send for $member's handle in $body, as the client counts. */
    private function at(Member $member, string $body): array
    {
        $handle = '@'.$member->name;

        return [
            'member_id' => $member->getKey(),
            'offset' => mb_strpos($body, $handle),
            'length' => mb_strlen($handle),
        ];
    }

    private function block(Member $blocker, Member $blocked): void
    {
        DB::table('member_blocks')->insert([
            'blocker_id' => $blocker->getKey(),
            'blocked_id' => $blocked->getKey(),
            'created_at' => now(),
        ]);
    }

    /** @param  list<array{member_id: int, offset: int, length: int}>  $expected */
    private function assertMentions(array $expected, TimelinePost $post): void
    {
        $stored = $post->fresh()->mentions
            ->map(fn ($mention): array => [
                'member_id' => $mention->member_id,
                'offset' => $mention->offset,
                'length' => $mention->length,
            ])
            ->all();

        $this->assertSame($expected, $stored);
    }
}
