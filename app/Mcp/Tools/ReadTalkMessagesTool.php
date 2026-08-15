<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Features\GroupTalk\GroupTalkCursor;
use App\Features\GroupTalk\Queries\GroupTalkMessages;
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

#[Name('read-talk-messages')]
#[Title('Read talk messages')]
#[Description('One page of a group talk room, oldest message first. Read the newest page, then walk back with mode=before or forward with mode=after, handing back a cursor the previous page returned.')]
#[IsReadOnly]
class ReadTalkMessagesTool extends TalkTool
{
    private const MODES = ['latest', 'before', 'after'];

    public function handle(Request $request, GroupTalkMessages $messages): Response|ResponseFactory
    {
        $validated = $request->validate([
            'group_id' => ['required', 'integer', 'min:1'],
            'mode' => ['sometimes', 'string', 'in:'.implode(',', self::MODES)],
            'cursor' => ['nullable', 'string', 'required_if:mode,before', 'required_if:mode,after'],
        ]);

        $group = $this->readableRoom($this->member($request), (int) $validated['group_id']);
        if ($group === null) {
            return $this->refused();
        }

        $mode = $validated['mode'] ?? 'latest';
        // A cursor the server did not hand out is refused rather than ignored: the web surface reads
        // an unparseable one as "no cursor" because dropping a page is worse than a 422 on a screen,
        // but a caller told "here is the newest page" when it asked for what came before would read
        // the same messages forever.
        $cursor = $mode === 'latest' ? null : GroupTalkCursor::tryParse($validated['cursor'] ?? null);
        if ($mode !== 'latest' && $cursor === null) {
            return $this->refused();
        }

        $page = match ($mode) {
            'before' => $messages->before($group, $cursor),
            'after' => $messages->after($group, $cursor),
            default => $messages->latest($group),
        };

        return Response::structured(McpTalkSerializer::page($page));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'group_id' => $schema->integer()->min(1)->required()
                ->description('The room to read, as list-talk-rooms reports it in groupId.'),
            'mode' => $schema->string()->enum(self::MODES)->default('latest')
                ->description('latest: the newest page. before: the page ending just before cursor. after: what has arrived since cursor.'),
            'cursor' => $schema->string()
                ->description('A cursor from an earlier page of this room — previousCursor for mode=before, nextCursor for mode=after. Required for both; ignored for latest. Opaque: never build one.'),
        ];
    }
}
