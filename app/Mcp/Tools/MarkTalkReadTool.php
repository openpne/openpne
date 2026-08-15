<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Features\GroupTalk\Actions\MarkTalkRead;
use App\Features\GroupTalk\Exceptions\GroupTalkActionException;
use App\Mcp\McpAbilities;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('mark-talk-read')]
#[Title('Mark a talk room read')]
#[Description('Record that you have read a room as far as one message. Only ever moves forward, so naming an older message changes nothing.')]
class MarkTalkReadTool extends TalkTool
{
    public function handle(Request $request, MarkTalkRead $markRead): Response
    {
        $member = $this->member($request);

        if (! $member->tokenCan(McpAbilities::WRITE)) {
            return Response::error(self::MISSING_WRITE);
        }

        $validated = $request->validate([
            'group_id' => ['required', 'integer', 'min:1'],
            'message_id' => ['required', 'integer', 'min:1'],
        ]);

        $group = $this->readableRoom($member, (int) $validated['group_id']);
        if ($group === null) {
            return $this->refused();
        }

        try {
            $markRead($member, $group, (int) $validated['message_id']);
        } catch (GroupTalkActionException) {
            // A message that is not in this room, or a reader with no membership row to hold a
            // cursor at all — an Everyone room is readable without joining it.
            return $this->refused();
        }

        return Response::text('Marked read through message '.$validated['message_id'].'.');
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'group_id' => $schema->integer()->min(1)->required()
                ->description('The room, as list-talk-rooms reports it in groupId.'),
            'message_id' => $schema->integer()->min(1)->required()
                ->description('The last message you have read, as read-talk-messages reports it in id.'),
        ];
    }
}
