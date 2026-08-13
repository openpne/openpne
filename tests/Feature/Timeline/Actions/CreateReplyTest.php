<?php

namespace Tests\Feature\Timeline\Actions;

use App\Features\Group\GroupRole;
use App\Features\GroupTopic\TopicReadAccess;
use App\Features\Timeline\Actions\CreateReply;
use App\Features\Timeline\Exceptions\NotGroupMember;
use App\Models\Group;
use App\Models\GroupMember;
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
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $replier = $this->joined($group);
        $parent = TimelinePost::factory()->inGroup($group)->create(['member_id' => $author->getKey()]);

        $reply = app(CreateReply::class)($replier, $parent, 'me too');

        // Asserted through the real writer, not the factory state: a reply that lost the scope here
        // would be an SNS-wide row inside a community thread.
        $this->assertSame($group->getKey(), $reply->fresh()->community_id);
    }

    public function test_a_non_member_cannot_reply_even_when_everyone_may_read(): void
    {
        // Reading an everyone-readable community does not admit someone to its conversation, and
        // the gate is in the action because the reply route is the SNS-wide one.
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $parent = TimelinePost::factory()->inGroup($group)->create([
            'member_id' => $this->joined($group)->getKey(),
        ]);

        $this->expectException(NotGroupMember::class);

        app(CreateReply::class)(Member::factory()->create(), $parent, 'let me in');
    }

    private function joined(Group $group): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'role' => GroupRole::Member,
        ]);

        return $member;
    }

    public function test_reply_carries_no_image(): void
    {
        $author = Member::factory()->create();
        $parent = TimelinePost::factory()->create(['member_id' => $author->getKey()]);

        $reply = app(CreateReply::class)($author, $parent, 'no image here');

        $this->assertCount(0, $reply->images);
    }
}
