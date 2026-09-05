<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Features\Diary\Queries\ShowDiary;
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

#[Name('read-diary')]
#[Title('Read a diary')]
#[Description('One diary in full — its whole body as plain text, and its whole comment thread in the order it was written. Reads any entry you may see, whether or not the feed lists it.')]
#[IsReadOnly]
class ReadDiaryTool extends DiaryTool
{
    public function handle(Request $request, ShowDiary $show): Response|ResponseFactory
    {
        $validated = $request->validate(['diary_id' => ['required', 'integer', 'min:1']]);

        $diary = $show($this->member($request), (int) $validated['diary_id']);
        if ($diary === null) {
            return $this->refused();
        }

        // Deliberately unpaged: a page of a diary's comments would leave a reader asking for the rest.
        $comments = $diary->comments()->with('member')->withCount('images')->orderBy('number')->get();

        return Response::structured(['diary' => McpDiarySerializer::detail($diary, $comments)]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'diary_id' => $schema->integer()->min(1)->required()
                ->description('The diary to read, as list-diaries reports it in diaryId.'),
        ];
    }
}
