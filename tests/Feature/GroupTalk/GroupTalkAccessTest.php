<?php

namespace Tests\Feature\GroupTalk;

use App\Features\GroupTopic\TopicPostAuthority;
use App\Features\GroupTopic\TopicReadAccess;
use App\Models\GroupMessage;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * Who may read a group's talk. The answer is the group's own read column — the one the board and
 * events already read — so a group answers "who may read this" the same way everywhere.
 */
class GroupTalkAccessTest extends TalkTestCase
{
    public function test_a_non_member_reads_an_everyone_group(): void
    {
        $group = $this->group(TopicReadAccess::Everyone);
        GroupMessage::factory()->create(['group_id' => $group->getKey()]);

        $this->actingAs(Member::factory()->create())
            ->get("/groups/{$group->getKey()}/talk")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('group/talk/index')->has('page.messages', 1));
    }

    public function test_a_members_only_group_is_a_404_for_a_non_member(): void
    {
        $group = $this->group(TopicReadAccess::MembersOnly);

        $this->actingAs(Member::factory()->create())
            ->get("/groups/{$group->getKey()}/talk")
            ->assertNotFound();

        $this->actingAs($this->memberOf($group))
            ->get("/groups/{$group->getKey()}/talk")
            ->assertOk();
    }

    public function test_the_json_endpoint_answers_the_same_gate(): void
    {
        $group = $this->group(TopicReadAccess::MembersOnly);

        $this->actingAs(Member::factory()->create())
            ->getJson("/groups/{$group->getKey()}/talk/messages")
            ->assertNotFound();
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $group = $this->group();

        $this->get("/groups/{$group->getKey()}/talk")->assertRedirect('/login');
    }

    public function test_a_reader_who_is_not_a_member_cannot_post(): void
    {
        $group = $this->group(TopicReadAccess::Everyone);
        $outsider = Member::factory()->create();

        // The page tells them so rather than offering a composer…
        $this->actingAs($outsider)
            ->get("/groups/{$group->getKey()}/talk")
            ->assertInertia(fn ($page) => $page->where('canPost', false));

        // …and the write refuses whatever the page rendered.
        $this->actingAs($outsider)
            ->postJson("/groups/{$group->getKey()}/talk", ['body' => 'hello'])
            ->assertNotFound();

        $this->assertDatabaseCount('group_messages', 0);
    }

    /**
     * Posting keys off membership alone: an admins-only board must not also silence the group's
     * chat, which is where the two gates deliberately come apart.
     */
    public function test_an_admins_only_board_does_not_silence_the_conversation(): void
    {
        $group = $this->group();
        $group->forceFill(['topic_post_authority' => TopicPostAuthority::AdminsOnly])->save();
        $member = $this->memberOf($group);

        $this->actingAs($member)
            ->postJson("/groups/{$group->getKey()}/talk", ['body' => 'still talking'])
            ->assertCreated();
    }

    /**
     * The community timeline this replaces hid a row whose author had left the group. Talk keeps it:
     * a conversation with holes is not the conversation that happened.
     */
    public function test_history_keeps_the_messages_of_someone_who_has_left_the_group(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $departed = Member::factory()->create();
        GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $departed->getKey()]);

        $this->actingAs($viewer)
            ->get("/groups/{$group->getKey()}/talk")
            ->assertInertia(fn ($page) => $page
                ->has('page.messages', 1)
                ->where('page.messages.0.author.id', $departed->getKey()));
    }

    /** Blocking gates people, not rooms: it never removes a message from the group's history. */
    public function test_history_keeps_the_messages_of_a_blocked_author(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $blocked = $this->memberOf($group);
        DB::table('member_blocks')->insert(['blocker_id' => $blocked->getKey(), 'blocked_id' => $viewer->getKey()]);
        GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $blocked->getKey()]);

        $this->actingAs($viewer)
            ->get("/groups/{$group->getKey()}/talk")
            ->assertInertia(fn ($page) => $page->has('page.messages', 1));
    }

    public function test_a_withdrawn_author_stays_listed_with_no_author(): void
    {
        $group = $this->group();
        GroupMessage::factory()->withdrawnAuthor()->create(['group_id' => $group->getKey(), 'body' => 'still here']);

        $this->actingAs($this->memberOf($group))
            ->get("/groups/{$group->getKey()}/talk")
            ->assertInertia(fn ($page) => $page
                ->has('page.messages', 1)
                ->where('page.messages.0.body', 'still here')
                ->where('page.messages.0.author', null));
    }

    public function test_withdrawing_leaves_the_message_and_clears_its_author(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);
        $message = GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $author->delete();

        $this->assertDatabaseHas('group_messages', ['id' => $message->getKey(), 'member_id' => null]);
    }
}
