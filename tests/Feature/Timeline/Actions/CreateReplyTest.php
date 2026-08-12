<?php

namespace Tests\Feature\Timeline\Actions;

use App\Features\Timeline\Actions\CreateReply;
use App\Models\Community;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_reply_is_a_child_row_inheriting_the_parents_visibility(): void
    {
        [$author, $replier] = Member::factory()->count(2)->create()->all();
        $parent = TimelinePost::factory()->friends()->create(['member_id' => $author->getKey()]);

        $reply = app(CreateReply::class)($replier, $parent, 'nice post');

        $this->assertSame($parent->getKey(), $reply->in_reply_to_id);
        $this->assertSame($replier->getKey(), $reply->member_id);
        $this->assertSame('nice post', $reply->body);
        // The reply inherits the thread's audience so the whole thread is gated as one.
        $this->assertSame(Visibility::Friends, $reply->visibility);
    }

    public function test_reply_to_a_community_post_stays_in_that_community(): void
    {
        [$author, $replier] = Member::factory()->count(2)->create()->all();
        $community = Community::factory()->create();
        $parent = TimelinePost::factory()->inCommunity($community)->create(['member_id' => $author->getKey()]);

        $reply = app(CreateReply::class)($replier, $parent, 'me too');

        // Asserted through the real writer, not the factory state: a reply that lost the scope here
        // would be an SNS-wide row inside a community thread.
        $this->assertSame($community->getKey(), $reply->fresh()->community_id);
    }

    public function test_reply_carries_no_image(): void
    {
        $author = Member::factory()->create();
        $parent = TimelinePost::factory()->create(['member_id' => $author->getKey()]);

        $reply = app(CreateReply::class)($author, $parent, 'no image here');

        $this->assertCount(0, $reply->images);
    }
}
