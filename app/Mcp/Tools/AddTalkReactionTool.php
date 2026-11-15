<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Features\GroupTalk\Actions\AddMessageReaction;
use App\Features\Reactions\Queries\ReactionAggregates;
use App\Http\Requests\Reactions\StoreReactionRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('add-talk-reaction')]
#[Title('React to a talk message')]
#[Description('Put an emoji on a message in a group talk room you belong to, as you — a quick acknowledgement that adds no message to the room and notifies nobody. Adding one you already hold changes nothing. Answers with the message\'s reactions as they now stand.')]
class AddTalkReactionTool extends TalkReactionTool
{
    public function handle(Request $request, AddMessageReaction $add, ReactionAggregates $reactions): Response|ResponseFactory
    {
        return $this->react($request, $reactions, $add(...), (new StoreReactionRequest)->rules()['emoji']);
    }
}
