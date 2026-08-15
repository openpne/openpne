<?php

namespace Tests\Feature\GroupTalk;

use App\Features\GroupTalk\TalkBody;
use App\Features\GroupTopic\TopicReadAccess;
use App\Features\Notifications\Serializers\NotificationFeedSerializer;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use App\Notifications\GroupTalk\GroupTalkMentionedNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Layer one of the failure contract, and the feed's click-time resolution.
 *
 * A payload the picker could not have produced is a broken client or tampering, so the whole message
 * is refused — unlike a row that merely went stale, which is dropped alone (TalkMentionStorageTest).
 */
class TalkMentionRequestTest extends TalkTestCase
{
    private function joined(Group $group, string $name): Member
    {
        $member = Member::factory()->create(['name' => $name]);
        DB::table('group_members')->insert([
            'group_id' => $group->getKey(), 'member_id' => $member->getKey(), 'role' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $member;
    }

    /** @return array<string, array{0: mixed}> */
    public static function structuralViolations(): array
    {
        return [
            'a non-integer member id' => [[['member_id' => 'abc', 'offset' => 0, 'length' => 4]]],
            'a negative offset' => [[['member_id' => 1, 'offset' => -1, 'length' => 4]]],
            'a missing length' => [[['member_id' => 1, 'offset' => 0]]],
            'a length below a handle' => [[['member_id' => 1, 'offset' => 0, 'length' => 1]]],
            'more rows than the cap' => [array_fill(0, 11, ['member_id' => 1, 'offset' => 0, 'length' => 4])],
            'not a list' => ['nope'],
        ];
    }

    #[DataProvider('structuralViolations')]
    public function test_a_structural_violation_refuses_the_whole_message(mixed $mentions): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);

        $this->actingAs($author)
            ->postJson("/groups/{$group->getKey()}/talk", ['body' => 'hello there', 'mentions' => $mentions])
            ->assertStatus(422);

        $this->assertDatabaseCount('group_messages', 0);
    }

    /** The bounds follow talk's own body cap, not the timeline's 140 — a mention may sit far into a long message. */
    public function test_an_offset_past_the_timeline_cap_is_accepted_here(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);
        $target = $this->joined($group, 'Bob');
        $offset = 500;
        $body = str_repeat('a', $offset).'@Bob';

        $this->actingAs($author)
            ->postJson("/groups/{$group->getKey()}/talk", [
                'body' => $body,
                'mentions' => [['member_id' => $target->getKey(), 'offset' => $offset, 'length' => 4]],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('group_message_mentions', ['offset' => $offset]);
    }

    public function test_an_offset_past_talks_own_cap_is_refused(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);

        $this->actingAs($author)
            ->postJson("/groups/{$group->getKey()}/talk", [
                'body' => 'hello',
                'mentions' => [['member_id' => 1, 'offset' => TalkBody::MAX, 'length' => 4]],
            ])
            ->assertStatus(422);
    }

    /** CRLF is normalized before offsets are measured, so a range after a newline still lands. */
    public function test_crlf_is_normalized_before_the_offsets_are_checked(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);
        $target = $this->joined($group, 'Bob');

        $this->actingAs($author)
            ->postJson("/groups/{$group->getKey()}/talk", [
                'body' => "one\r\n@Bob",
                'mentions' => [['member_id' => $target->getKey(), 'offset' => 4, 'length' => 4]],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('group_message_mentions', ['offset' => 4]);
    }

    private function feedRow(Member $recipient, Group $group, GroupMessage $message, Member $author): DatabaseNotification
    {
        return $recipient->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => GroupTalkMentionedNotification::class,
            'data' => [
                'kind' => 'group_talk_mention',
                'author_id' => $author->getKey(),
                'group_id' => $group->getKey(),
                'message_id' => $message->getKey(),
            ],
        ]);
    }

    /** On the message that named them: talk has no screen for one message, so `?m=` is the address. */
    public function test_the_feed_row_opens_the_conversation_on_the_message(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);
        $target = $this->joined($group, 'Bob');
        $message = GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $url = NotificationFeedSerializer::targetUrl($this->feedRow($target, $group, $message, $author));

        $this->assertSame("/groups/{$group->getKey()}/talk?m={$message->getKey()}", $url);
    }

    /** Fail-closed: a message deleted since delivery resolves to nowhere, and the feed keeps the reader. */
    public function test_a_deleted_message_resolves_to_no_target(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);
        $target = $this->joined($group, 'Bob');
        $message = GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $row = $this->feedRow($target, $group, $message, $author);

        $message->delete();

        $this->assertNull(NotificationFeedSerializer::targetUrl($row));
    }

    /** Read access is re-asked at click time, not trusted from delivery. */
    public function test_a_reader_who_lost_access_resolves_to_no_target(): void
    {
        $group = $this->group(TopicReadAccess::MembersOnly);
        $author = $this->memberOf($group);
        $target = $this->joined($group, 'Bob');
        $message = GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $row = $this->feedRow($target, $group, $message, $author);

        $this->assertNotNull(NotificationFeedSerializer::targetUrl($row));

        DB::table('group_members')->where('member_id', $target->getKey())->delete();

        $this->assertNull(NotificationFeedSerializer::targetUrl($row->fresh()));
    }
}
