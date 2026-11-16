<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Features\GroupTalk\Actions\AddMessageReaction;
use App\Features\Reactions\Queries\ReactionAggregates;
use App\Features\Reactions\ReactionVocabulary;
use App\Http\Requests\Reactions\StoreReactionRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('add-talk-reaction')]
#[Title('React to a talk message')]
#[Description('Put an emoji on a message in a group talk room you belong to, as you — a quick acknowledgement that adds no message to the room and notifies nobody. Adding one you already hold changes nothing. Answers with the message\'s reactions as they now stand.')]
#[IsIdempotent]
#[IsDestructive(false)]
class AddTalkReactionTool extends TalkReactionTool
{
    public function handle(Request $request, AddMessageReaction $add, ReactionAggregates $reactions): Response|ResponseFactory
    {
        return $this->react($request, $reactions, $add(...), (new StoreReactionRequest)->rules()['emoji']);
    }

    protected function emojiSchema(JsonSchema $schema): Type
    {
        return $schema->string()->enum(ReactionVocabulary::all())->required()
            ->description('One of the site\'s emoji.');
    }
}
