<?php

namespace Tests\Feature\GroupTalk;

use App\Http\Requests\GroupTalk\StoreGroupMessageRequest;
use App\Models\GroupMessage;
use App\Models\Member;
use PHPUnit\Framework\Attributes\DataProvider;

/** Saying something, and taking it back. */
class GroupTalkWriteTest extends TalkTestCase
{
    public function test_a_member_posts_and_gets_the_message_back(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);

        $response = $this->actingAs($member)
            ->postJson("/groups/{$group->getKey()}/talk", ['body' => 'good morning'])
            ->assertCreated()
            ->assertJsonPath('body', 'good morning')
            ->assertJsonPath('author.id', $member->getKey())
            ->assertJsonPath('isOwn', true)
            ->assertJsonPath('canDelete', true);

        $this->assertDatabaseHas('group_messages', [
            'id' => $response->json('id'),
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'body' => 'good morning',
            // Lineage only: the composer never writes a parent.
            'in_reply_to_id' => null,
        ]);
    }

    public function test_an_empty_body_is_rejected(): void
    {
        $group = $this->group();

        $this->actingAs($this->memberOf($group))
            ->postJson("/groups/{$group->getKey()}/talk", ['body' => ''])
            ->assertJsonValidationErrorFor('body');

        $this->assertDatabaseCount('group_messages', 0);
    }

    public function test_a_body_of_exactly_the_cap_is_accepted_and_one_point_over_is_not(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $max = StoreGroupMessageRequest::MAX_BODY;

        $this->actingAs($member)
            ->postJson("/groups/{$group->getKey()}/talk", ['body' => str_repeat('a', $max)])
            ->assertCreated();

        $this->actingAs($member)
            ->postJson("/groups/{$group->getKey()}/talk", ['body' => str_repeat('a', $max + 1)])
            ->assertJsonValidationErrorFor('body');

        $this->assertDatabaseCount('group_messages', 1);
    }

    /**
     * The cap counts code points, not bytes and not UTF-16 units: a body of astral emoji is as long
     * as one of ASCII, and PHP's mb_strlen and JavaScript's Array.from() agree on the number.
     */
    public function test_the_cap_counts_code_points_not_bytes_or_utf16_units(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $max = StoreGroupMessageRequest::MAX_BODY;

        // 4 bytes and 2 UTF-16 units each: 5,000 of them are far over either of those limits.
        $emoji = str_repeat('🙂', $max);
        $this->assertSame($max * 4, strlen($emoji));

        $this->actingAs($member)
            ->postJson("/groups/{$group->getKey()}/talk", ['body' => $emoji])
            ->assertCreated();

        $this->actingAs($member)
            ->postJson("/groups/{$group->getKey()}/talk", ['body' => $emoji.'🙂'])
            ->assertJsonValidationErrorFor('body');
    }

    /**
     * A textarea submits CRLF; the body is stored with LF. The normalization runs before the length
     * check, so a body is never measured a line break longer than the one that was typed.
     */
    public function test_crlf_is_normalized_to_lf_before_it_is_measured_and_stored(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);

        $id = $this->actingAs($member)
            ->postJson("/groups/{$group->getKey()}/talk", ['body' => "one\r\ntwo\rthree"])
            ->assertCreated()
            ->json('id');

        $this->assertSame("one\ntwo\nthree", GroupMessage::findOrFail($id)->body);

        // Measured after normalization: this is over the cap only if the CRLFs are counted as two.
        $max = StoreGroupMessageRequest::MAX_BODY;
        $this->actingAs($member)
            ->postJson("/groups/{$group->getKey()}/talk", ['body' => str_repeat("a\r\n", $max / 2)])
            ->assertCreated();
    }

    public function test_the_author_deletes_their_own_message(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);
        $message = GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($author)
            ->post("/groups/{$group->getKey()}/talk/messages/{$message->getKey()}/delete")
            ->assertNoContent();

        $this->assertDatabaseMissing('group_messages', ['id' => $message->getKey()]);
    }

    /** @return list<array{0: string}> */
    public static function managingRoles(): array
    {
        return [['adminOf'], ['subAdminOf']];
    }

    #[DataProvider('managingRoles')]
    public function test_whoever_manages_the_group_deletes_anyone_s_message(string $role): void
    {
        $group = $this->group();
        $message = GroupMessage::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $this->memberOf($group)->getKey(),
        ]);

        $this->actingAs($this->{$role}($group))
            ->post("/groups/{$group->getKey()}/talk/messages/{$message->getKey()}/delete")
            ->assertNoContent();

        $this->assertDatabaseMissing('group_messages', ['id' => $message->getKey()]);
    }

    public function test_an_ordinary_member_cannot_delete_someone_else_s_message(): void
    {
        $group = $this->group();
        $message = GroupMessage::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $this->memberOf($group)->getKey(),
        ]);

        $this->actingAs($this->memberOf($group))
            ->post("/groups/{$group->getKey()}/talk/messages/{$message->getKey()}/delete")
            ->assertNotFound();

        $this->assertDatabaseHas('group_messages', ['id' => $message->getKey()]);
    }

    public function test_an_outsider_cannot_delete_anything(): void
    {
        $group = $this->group();
        $message = GroupMessage::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $this->memberOf($group)->getKey(),
        ]);

        $this->actingAs(Member::factory()->create())
            ->post("/groups/{$group->getKey()}/talk/messages/{$message->getKey()}/delete")
            ->assertNotFound();

        $this->assertDatabaseHas('group_messages', ['id' => $message->getKey()]);
    }

    /** The path names both, so a message reached through the wrong group is a malformed URL. */
    public function test_a_message_addressed_through_another_group_is_a_404(): void
    {
        $group = $this->group();
        $elsewhere = $this->group();
        $author = $this->memberOf($group);
        // An admin of the other group, so only the group/message mismatch can be what refuses.
        $intruder = $this->adminOf($elsewhere);
        $message = GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($intruder)
            ->post("/groups/{$elsewhere->getKey()}/talk/messages/{$message->getKey()}/delete")
            ->assertNotFound();

        $this->assertDatabaseHas('group_messages', ['id' => $message->getKey()]);
    }
}
