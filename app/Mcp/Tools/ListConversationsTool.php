<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Features\DirectMessage\Queries\ConversationList;
use App\Features\DirectMessage\Serializers\McpConversationSerializer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-conversations')]
#[Title('List direct-message conversations')]
#[Description('Your one-to-one direct-message conversations, the one written in most recently first, each with the other member, how many of their messages you have not read, and a one-line preview of the latest. A conversation with a member who has left is listed with a null memberId; it cannot be opened or marked read here, so its unread count stays.')]
#[IsReadOnly]
class ListConversationsTool extends DirectMessageTool
{
    public function handle(Request $request, ConversationList $conversations): ResponseFactory|Response
    {
        $validated = $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        // There is no URL here, so the query's own page resolver would answer page one every time.
        $page = $conversations($this->member($request), ConversationList::PER_PAGE, (int) ($validated['page'] ?? 1));

        return Response::structured(McpConversationSerializer::conversations($page));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->min(1)->default(1)
                ->description('Which page of conversations to return, '.ConversationList::PER_PAGE.' to a page.'),
        ];
    }
}
