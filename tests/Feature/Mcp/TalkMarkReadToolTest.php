<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Features\GroupTopic\TopicReadAccess;
use App\Mcp\Servers\OpenPneServer;
use App\Mcp\Tools\MarkTalkReadTool;
use App\Models\Member;

class TalkMarkReadToolTest extends TalkToolsTestCase
{
    public function test_marking_read_moves_the_cursor_forward_only(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $author = $this->memberOf($group);
        $first = $this->say($group, $author, 'one');
        $second = $this->say($group, $author, 'two');

        $this->acting($member);

        OpenPneServer::tool(MarkTalkReadTool::class, [
            'group_id' => $group->getKey(),
            'message_id' => $second->getKey(),
        ])->assertOk();

        $this->assertSame($second->getKey(), $this->readCursor($group, $member));

        OpenPneServer::tool(MarkTalkReadTool::class, [
            'group_id' => $group->getKey(),
            'message_id' => $first->getKey(),
        ])->assertOk();

        $this->assertSame($second->getKey(), $this->readCursor($group, $member));
    }

    public function test_marking_read_refuses_a_message_from_another_room_and_a_reader_with_no_cursor(): void
    {
        $group = $this->group();
        $elsewhere = $this->group();
        $stranger = $this->memberOf($elsewhere);
        $foreign = $this->say($elsewhere, $stranger, 'elsewhere');

        $member = $this->memberOf($group);
        $this->acting($member);

        OpenPneServer::tool(MarkTalkReadTool::class, [
            'group_id' => $group->getKey(),
            'message_id' => $foreign->getKey(),
        ])->assertHasErrors(['No such talk room']);

        // An Everyone room is readable without joining it, and a non-member holds no membership row
        // to carry a cursor on.
        $open = $this->group(TopicReadAccess::Everyone);
        $message = $this->say($open, $this->memberOf($open), 'open');
        $this->acting(Member::factory()->create());

        OpenPneServer::tool(MarkTalkReadTool::class, [
            'group_id' => $open->getKey(),
            'message_id' => $message->getKey(),
        ])->assertHasErrors(['No such talk room']);
    }
}
