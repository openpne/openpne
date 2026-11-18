<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Features\DirectMessage\Actions\MarkConversationRead;
use App\Features\DirectMessage\DirectMessageNotificationRows;
use App\Features\DirectMessage\Exceptions\DirectMessageActionException;
use App\Mcp\McpAbilities;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('mark-direct-messages-read')]
#[Title('Mark a direct-message conversation read')]
#[Description('Record that you have read your conversation with a member as far as one message: every message they sent you up to it is marked read. Naming an older message changes nothing already read.')]
class MarkDirectMessagesReadTool extends DirectMessageTool
{
    public function handle(Request $request, MarkConversationRead $markRead, DirectMessageNotificationRows $feedRows): Response
    {
        $viewer = $this->member($request);

        if (! $viewer->tokenCan(McpAbilities::WRITE)) {
            return Response::error(self::MISSING_WRITE);
        }

        $validated = $request->validate([
            'member_id' => ['required', 'integer', 'min:1'],
            'message_id' => ['required', 'integer', 'min:1'],
        ]);

        $counterpart = $this->counterpart($viewer, (int) $validated['member_id']);
        if ($counterpart === null) {
            return $this->refused();
        }

        try {
            $marked = $markRead($viewer, $counterpart, (int) $validated['message_id']);
        } catch (DirectMessageActionException) {
            return $this->refused();
        }

        // The notification feed rows the screen's own report clears, so the bell agrees.
        $feedRows->markReadFor($viewer);

        return Response::text("Marked {$marked} message(s) read through message {$validated['message_id']}.");
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'member_id' => $schema->integer()->min(1)->required()
                ->description('The other member, as list-conversations reports it in memberId.'),
            'message_id' => $schema->integer()->min(1)->required()
                ->description('The last message you have read, as read-direct-messages reports it in id.'),
        ];
    }
}
