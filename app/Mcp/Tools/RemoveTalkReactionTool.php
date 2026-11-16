<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Features\GroupTalk\Actions\RemoveMessageReaction;
use App\Features\Reactions\Queries\ReactionAggregates;
use App\Http\Requests\Reactions\StoreReactionRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('remove-talk-reaction')]
#[Title('Take back a talk reaction')]
#[Description('Take your own emoji off a message in a group talk room. Removing one you do not hold changes nothing. Answers with the message\'s reactions as they now stand.')]
#[IsIdempotent]
class RemoveTalkReactionTool extends TalkReactionTool
{
    public function handle(Request $request, RemoveMessageReaction $remove, ReactionAggregates $reactions): Response|ResponseFactory
    {
        return $this->react($request, $reactions, $remove(...), StoreReactionRequest::removeRules()['emoji']);
    }

    protected function emojiSchema(JsonSchema $schema): Type
    {
        return $schema->string()->required()
            ->description('The emoji to take back, as read-talk-messages reports it.');
    }
}
