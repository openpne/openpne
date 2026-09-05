<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Features\Diary\Queries\ListRecentDiaries;
use App\Features\Diary\Serializers\McpDiarySerializer;
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
    public function handle(Request $request, ListRecentDiaries $recent): ResponseFactory
    {
        $validated = $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        // There is no URL here, so the query's own page resolver would answer page one every time.
        $page = $recent(
            $this->member($request),
            ListRecentDiaries::PER_PAGE,
            (int) ($validated['page'] ?? 1),
        );

        return Response::structured(McpDiarySerializer::diaries($page));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->min(1)->default(1)
                ->description('Which page of diaries to return, '.ListRecentDiaries::PER_PAGE.' to a page.'),
        ];
    }
}
