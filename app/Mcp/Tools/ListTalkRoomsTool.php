<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Features\GroupTalk\Queries\JoinedTalkRooms;
use App\Features\GroupTalk\Serializers\McpTalkSerializer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-talk-rooms')]
#[Title('List talk rooms')]
#[Description('The group talk rooms you belong to, the one talked in most recently first, each with how many messages you have not read and how many of those are waiting for you — the ones that mention you or reply to something you said.')]
#[IsReadOnly]
class ListTalkRoomsTool extends TalkTool
{
    public function handle(Request $request, JoinedTalkRooms $rooms): ResponseFactory
    {
        $validated = $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        // The page is named rather than read off a URL: there is none here, so the query's own
        // resolver would answer page one every time. The addressed count is asked for here and
        // nowhere else — it is the whole reason an agent can poll one call instead of reading every
        // room.
        $page = $rooms(
            $this->member($request),
            JoinedTalkRooms::PER_PAGE,
            (int) ($validated['page'] ?? 1),
            withUnreadMentions: true,
        );

        return Response::structured(McpTalkSerializer::rooms($page));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->min(1)->default(1)
                ->description('Which page of rooms to return, '.JoinedTalkRooms::PER_PAGE.' to a page.'),
        ];
    }
}
