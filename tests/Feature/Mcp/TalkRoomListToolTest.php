<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\OpenPneServer;
use App\Mcp\Tools\ListTalkRoomsTool;
use App\Mcp\Tools\MarkTalkReadTool;
use App\Models\GroupMember;
use App\Models\Member;

class TalkRoomListToolTest extends TalkToolsTestCase
{
    public function test_the_room_list_is_the_callers_own_rooms_newest_conversation_first(): void
    {
        $quiet = $this->group();
        $busy = $this->group();
        $elsewhere = $this->group();

        $member = Member::factory()->create();
        GroupMember::factory()->create(['group_id' => $quiet->getKey(), 'member_id' => $member->getKey()]);
        GroupMember::factory()->create(['group_id' => $busy->getKey(), 'member_id' => $member->getKey()]);

        $other = $this->memberOf($busy);
        $this->say($busy, $other, 'hello');
        $this->say($elsewhere, $this->memberOf($elsewhere), 'not yours');

        $this->acting($member);

        OpenPneServer::tool(ListTalkRoomsTool::class)
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('rooms.0.groupId', $busy->getKey())
                ->where('rooms.0.unread', 1)
                ->where('rooms.1.groupId', $quiet->getKey())
                ->where('rooms.1.unread', 0)
                ->where('rooms.1.lastMessageAt', null)
                ->where('total', 2)
                ->etc());
    }

    public function test_the_room_list_pages_where_it_is_told_to(): void
    {
        $member = Member::factory()->create();
        foreach (range(1, 21) as $ignored) {
            GroupMember::factory()->create([
                'group_id' => $this->group()->getKey(),
                'member_id' => $member->getKey(),
            ]);
        }

        $this->acting($member);

        OpenPneServer::tool(ListTalkRoomsTool::class, ['page' => 2])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('page', 2)->where('lastPage', 2)->count('rooms', 1)->etc());
    }

    public function test_the_room_list_counts_an_answer_to_something_the_caller_said(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $other = $this->memberOf($group);

        $mine = $this->say($group, $viewer, 'what is the weather');
        $this->answering($group, $other, 'rain, probably', $mine);
        $this->say($group, $other, 'unrelated chatter');

        $this->acting($viewer);

        OpenPneServer::tool(ListTalkRoomsTool::class)
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('rooms.0.unread', 2)
                ->where('rooms.0.unreadMentions', 1)
                ->etc());
    }

    public function test_the_room_list_says_how_many_of_the_unread_name_the_caller(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $other = $this->memberOf($group);
        $bystander = $this->memberOf($group);

        $this->names($this->say($group, $other, 'hey'), $viewer);
        // Named twice in one line: one message waiting, not two.
        $twice = $this->say($group, $other, 'hey again');
        $this->names($twice, $viewer);
        $this->names($twice, $viewer, offset: 20);
        $addressedToSomeoneElse = $this->say($group, $other, 'not you');
        $this->names($addressedToSomeoneElse, $bystander);

        $this->acting($viewer);

        OpenPneServer::tool(ListTalkRoomsTool::class)
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('rooms.0.unread', 3)
                ->where('rooms.0.unreadMentions', 2)
                ->etc());

        OpenPneServer::tool(MarkTalkReadTool::class, [
            'group_id' => $group->getKey(),
            'message_id' => $addressedToSomeoneElse->getKey(),
        ])->assertOk();

        OpenPneServer::tool(ListTalkRoomsTool::class)
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('rooms.0.unread', 0)
                ->where('rooms.0.unreadMentions', 0)
                ->etc());
    }
}
