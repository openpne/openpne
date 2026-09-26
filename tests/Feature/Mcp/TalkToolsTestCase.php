<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\McpAbilities;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\Member;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

abstract class TalkToolsTestCase extends McpTestCase
{
    protected function acting(Member $member, array $abilities = [McpAbilities::READ, McpAbilities::WRITE]): Member
    {
        return Sanctum::actingAs($member, $abilities);
    }

    /** One message in the room answering another, as the composer and the reply tool both write one. */
    protected function answering(Group $group, Member $author, string $body, GroupMessage $parent): GroupMessage
    {
        return GroupMessage::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $author->getKey(),
            'body' => $body,
            'in_reply_to_id' => $parent->getKey(),
        ]);
    }

    /** A member of the room under a name of their own, since a composed handle is that name. */
    protected function joined(Group $group, string $name): Member
    {
        $member = Member::factory()->create(['name' => $name]);
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);

        return $member;
    }

    /** A mention row naming $member in $message, as the web surface's picker writes one. */
    protected function names(GroupMessage $message, Member $member, int $offset = 0): void
    {
        DB::table('group_message_mentions')->insert([
            'group_message_id' => $message->getKey(),
            'member_id' => $member->getKey(),
            'offset' => $offset,
            'length' => 1 + mb_strlen($member->name),
        ]);
    }

    protected function readCursor(Group $group, Member $member): ?int
    {
        $value = DB::table('group_members')
            ->where('group_id', $group->getKey())
            ->where('member_id', $member->getKey())
            ->value('talk_read_message_id');

        return $value === null ? null : (int) $value;
    }
}
