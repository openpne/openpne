<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Features\GroupTalk\Exceptions\GroupTalkActionException;
use App\Features\GroupTalk\GroupTalkAccess;
use App\Features\Reactions\Queries\ReactionAggregates;
use App\Mcp\McpAbilities;
use App\Models\GroupMessage;
use App\Models\Member;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Reacting is speaking in the room, so the gate is `canPost`, and a room the caller may read but not
 * write to answers as a room that is not there. The emoji is validated after the gate, so an invalid
 * payload cannot tell a room that exists from one that does not.
 */
abstract class TalkReactionTool extends TalkTool
{
    /**
     * @param  callable(Member, GroupMessage, string): void  $write
     * @param  array<int, mixed>  $emojiRules
     */
    protected function react(Request $request, ReactionAggregates $reactions, callable $write, array $emojiRules): Response|ResponseFactory
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
        if ($group === null || ! GroupTalkAccess::canPost($group, $member)) {
            return $this->refused();
        }

        $message = GroupMessage::query()
            ->where('group_id', $group->getKey())
            ->whereKey((int) $validated['message_id'])
            ->first();

        if ($message === null) {
            return $this->refused();
        }

        $emoji = (string) Validator::make(['emoji' => $request->get('emoji')], ['emoji' => $emojiRules])->validate()['emoji'];

        try {
            $write($member, $message, $emoji);
        } catch (GroupTalkActionException) {
            // Gone between the read above and the write's own lock.
            return $this->refused();
        }

        return Response::structured([
            'messageId' => (int) $message->getKey(),
            'reactions' => $reactions->of($member, $message),
        ]);
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
                ->description('The message, as read-talk-messages reports it in id.'),
            'emoji' => $this->emojiSchema($schema),
        ];
    }

    abstract protected function emojiSchema(JsonSchema $schema): Type;
}
