<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Features\DirectMessage\ConversationCursor;
use App\Features\DirectMessage\Queries\ConversationMessages;
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

#[Name('read-direct-messages')]
#[Title('Read a direct-message conversation')]
#[Description('One page of your direct-message conversation with a member, oldest message first. Read the newest page, then walk back with mode=before or forward with mode=after, handing back a cursor the previous page returned. Reading marks nothing read; mark-direct-messages-read does.')]
#[IsReadOnly]
class ReadDirectMessagesTool extends DirectMessageTool
{
    private const MODES = ['latest', 'before', 'after'];

    public function handle(Request $request, ConversationMessages $messages): Response|ResponseFactory
    {
        $validated = $request->validate([
            'member_id' => ['required', 'integer', 'min:1'],
            'mode' => ['sometimes', 'string', 'in:'.implode(',', self::MODES)],
            'cursor' => ['nullable', 'string', 'required_if:mode,before', 'required_if:mode,after'],
        ]);

        $viewer = $this->member($request);
        $counterpart = $this->counterpart($viewer, (int) $validated['member_id']);
        if ($counterpart === null) {
            return $this->refused();
        }

        $mode = $validated['mode'] ?? 'latest';
        // A cursor that does not parse is refused rather than read as "no cursor", for the reason
        // talk's is: a client told "here is the newest page" would loop over it forever.
        $cursor = $mode === 'latest' ? null : ConversationCursor::tryParse($validated['cursor'] ?? null);
        if ($mode !== 'latest' && $cursor === null) {
            return $this->refused();
        }

        // A member the caller has never written with is an empty conversation, not a refusal: the
        // screen opens one the same way, and saying otherwise would tell who has written to whom.
        $page = match ($mode) {
            'before' => $messages->before($viewer, $counterpart, $cursor),
            'after' => $messages->after($viewer, $counterpart, $cursor),
            default => $messages->latest($viewer, $counterpart),
        };

        return Response::structured([
            'memberId' => (int) $counterpart->getKey(),
            ...McpConversationSerializer::page($page, $viewer, $counterpart),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'member_id' => $schema->integer()->min(1)->required()
                ->description('The other member, as list-conversations reports it in memberId.'),
            'mode' => $schema->string()->enum(self::MODES)->default('latest')
                ->description('latest: the newest page. before: the page ending just before cursor. after: what has arrived since cursor.'),
            'cursor' => $schema->string()
                ->description('A cursor from an earlier page of this conversation — previousCursor for mode=before, nextCursor for mode=after. Opaque: never build one.'),
        ];
    }
}
