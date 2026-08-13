<?php

namespace Tests\Feature\GroupTalk;

use App\Features\GroupTopic\TopicReadAccess;
use App\Models\GroupMessage;
use App\Models\Member;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** "I have read as far as this message": the server resolves the tuple, and only ever forward. */
class MarkTalkReadTest extends TalkTestCase
{
    private function cursorOf(int $groupId, int $memberId): object
    {
        return DB::table('group_members')
            ->where('group_id', $groupId)->where('member_id', $memberId)
            ->first(['talk_read_at', 'talk_read_message_id']);
    }

    public function test_marking_read_moves_the_cursor_to_the_named_message(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $messages = GroupMessage::factory()->count(3)->create(['group_id' => $group->getKey()]);

        $this->actingAs($member)
            ->postJson("/groups/{$group->getKey()}/talk/read", ['messageId' => $messages[1]->getKey()])
            ->assertNoContent();

        $this->assertSame((int) $messages[1]->getKey(), (int) $this->cursorOf($group->getKey(), $member->getKey())->talk_read_message_id);
    }

    /**
     * The cursor is what the client says it has SEEN, never the group's current newest — otherwise a
     * message that arrived between the page loading and this call would be marked read unseen.
     */
    public function test_marking_read_does_not_jump_to_messages_that_arrived_since(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $seen = GroupMessage::factory()->create(['group_id' => $group->getKey()]);
        $arrivedSince = GroupMessage::factory()->create(['group_id' => $group->getKey()]);

        $this->actingAs($member)
            ->postJson("/groups/{$group->getKey()}/talk/read", ['messageId' => $seen->getKey()])
            ->assertNoContent();

        $cursor = $this->cursorOf($group->getKey(), $member->getKey());
        $this->assertSame((int) $seen->getKey(), (int) $cursor->talk_read_message_id);
        $this->assertNotSame((int) $arrivedSince->getKey(), (int) $cursor->talk_read_message_id);
    }

    /** Idempotent: replaying an older position — a retry, a second tab behind — changes nothing. */
    public function test_replaying_an_older_message_never_moves_the_cursor_back(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $messages = GroupMessage::factory()->count(3)->create(['group_id' => $group->getKey()]);
        $url = "/groups/{$group->getKey()}/talk/read";

        $this->actingAs($member)->postJson($url, ['messageId' => $messages[2]->getKey()])->assertNoContent();
        $this->actingAs($member)->postJson($url, ['messageId' => $messages[0]->getKey()])->assertNoContent();
        $this->actingAs($member)->postJson($url, ['messageId' => $messages[2]->getKey()])->assertNoContent();

        $this->assertSame((int) $messages[2]->getKey(), (int) $this->cursorOf($group->getKey(), $member->getKey())->talk_read_message_id);
    }

    /** Same second, different ids: the id half of the tuple has to break the tie forward. */
    public function test_the_cursor_advances_between_two_messages_of_the_same_second(): void
    {
        Carbon::setTestNow('2026-08-14 09:00:00');
        $group = $this->group();
        $member = $this->memberOf($group);
        $first = GroupMessage::factory()->create(['group_id' => $group->getKey()]);
        $second = GroupMessage::factory()->create(['group_id' => $group->getKey()]);
        $url = "/groups/{$group->getKey()}/talk/read";

        $this->actingAs($member)->postJson($url, ['messageId' => $second->getKey()])->assertNoContent();
        $this->actingAs($member)->postJson($url, ['messageId' => $first->getKey()])->assertNoContent();

        $this->assertSame((int) $second->getKey(), (int) $this->cursorOf($group->getKey(), $member->getKey())->talk_read_message_id);
    }

    public function test_a_message_of_another_group_is_refused(): void
    {
        $group = $this->group();
        $elsewhere = $this->group();
        $member = $this->memberOf($group);
        $this->memberOf($elsewhere);
        $foreign = GroupMessage::factory()->create(['group_id' => $elsewhere->getKey()]);

        $this->actingAs($member)
            ->postJson("/groups/{$group->getKey()}/talk/read", ['messageId' => $foreign->getKey()])
            ->assertNotFound();

        $this->assertSame(0, (int) $this->cursorOf($group->getKey(), $member->getKey())->talk_read_message_id);
    }

    public function test_a_deleted_message_is_refused(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $message = GroupMessage::factory()->create(['group_id' => $group->getKey()]);
        $id = $message->getKey();
        $message->delete();

        $this->actingAs($member)
            ->postJson("/groups/{$group->getKey()}/talk/read", ['messageId' => $id])
            ->assertNotFound();
    }

    /** A reader with no membership row has no cursor to hold, whatever they may read. */
    public function test_a_non_member_reader_cannot_mark_read(): void
    {
        $group = $this->group(TopicReadAccess::Everyone);
        $message = GroupMessage::factory()->create(['group_id' => $group->getKey()]);
        $outsider = Member::factory()->create();

        $this->actingAs($outsider)->get("/groups/{$group->getKey()}/talk")->assertOk();
        $this->actingAs($outsider)
            ->postJson("/groups/{$group->getKey()}/talk/read", ['messageId' => $message->getKey()])
            ->assertNotFound();
    }

    public function test_a_missing_or_malformed_id_is_a_validation_error(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);

        $this->actingAs($member)
            ->postJson("/groups/{$group->getKey()}/talk/read", [])
            ->assertJsonValidationErrorFor('messageId');

        $this->actingAs($member)
            ->postJson("/groups/{$group->getKey()}/talk/read", ['messageId' => 'nope'])
            ->assertJsonValidationErrorFor('messageId');
    }

    /** Writing is reading — and it happens with the insert, not on the next page load. */
    public function test_sending_a_message_advances_the_sender_s_own_cursor(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);

        $id = $this->actingAs($member)
            ->postJson("/groups/{$group->getKey()}/talk", ['body' => 'hello'])
            ->assertCreated()
            ->json('id');

        $this->assertSame((int) $id, (int) $this->cursorOf($group->getKey(), $member->getKey())->talk_read_message_id);
    }
}
