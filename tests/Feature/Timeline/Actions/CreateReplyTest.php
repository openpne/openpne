<?php

namespace Tests\Feature\Timeline\Actions;

use App\Features\Timeline\Actions\CreateReply;
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

        $reply = (new CreateReply)($replier, $parent, 'nice post');

        $this->assertSame($parent->getKey(), $reply->in_reply_to_id);
        $this->assertSame($replier->getKey(), $reply->member_id);
        $this->assertSame('nice post', $reply->body);
        // The reply inherits the thread's audience so the whole thread is gated as one.
        $this->assertSame(Visibility::Friends, $reply->visibility);
    }

    public function test_reply_carries_no_image(): void
    {
        $author = Member::factory()->create();
        $parent = TimelinePost::factory()->create(['member_id' => $author->getKey()]);

        $reply = (new CreateReply)($author, $parent, 'no image here');

        $this->assertCount(0, $reply->images);
    }
}
