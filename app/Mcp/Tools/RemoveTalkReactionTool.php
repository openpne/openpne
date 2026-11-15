<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Features\GroupTalk\Actions\RemoveMessageReaction;
use App\Features\Reactions\Queries\ReactionAggregates;
use App\Http\Requests\Reactions\StoreReactionRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('remove-talk-reaction')]
#[Title('Take back a talk reaction')]
#[Description('Take your own emoji off a message in a group talk room. Removing one you do not hold changes nothing. Answers with the message\'s reactions as they now stand.')]
class RemoveTalkReactionTool extends TalkReactionTool
{
    /** Checked for shape only, never against the set: an emoji the site withdrew can still be taken back. */
    public function handle(Request $request, RemoveMessageReaction $remove, ReactionAggregates $reactions): Response|ResponseFactory
    {
        return $this->react($request, $reactions, $remove(...), StoreReactionRequest::removeRules()['emoji']);
    }
}
