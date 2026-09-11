<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Features\Diary\Queries\ListRecentDiaries;
use App\Features\Diary\Serializers\McpDiarySerializer;
use App\Support\Stream\StreamCursor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-diaries')]
#[Title('List diaries')]
#[Description('The site\'s newest diaries, most recently posted first: every member\'s entries open to the membership at large, with a one-line excerpt of each. This is the feed, so friends-only and private entries are not in it — not even your own, which read-diary still reads by id.')]
#[IsReadOnly]
class ListDiariesTool extends DiaryTool
{
    public function handle(Request $request, ListRecentDiaries $recent): ResponseFactory|Response
    {
        $validated = $request->validate(['before' => ['sometimes', 'string']]);

        $before = null;
        if (isset($validated['before'])) {
            // A cursor this server did not hand out is refused, as every other opaque id here is.
            $before = StreamCursor::tryParse($validated['before']);
            if ($before === null) {
                return $this->refused();
            }
        }

        return Response::structured(McpDiarySerializer::diaries($recent($this->member($request), $before)));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'before' => $schema->string()
                ->description('The olderCursor of an earlier answer, to read the '.ListRecentDiaries::PER_PAGE.' diaries before it; omit for the newest.'),
        ];
    }
}
