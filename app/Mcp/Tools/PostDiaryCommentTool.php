<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Features\Diary\Actions\CreateComment;
use App\Features\Diary\Queries\ShowDiary;
use App\Features\Diary\Serializers\McpDiarySerializer;
use App\Mcp\McpAbilities;
use App\Rules\MaxBytes;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('post-diary-comment')]
#[Title('Comment on a diary')]
#[Description('Add a comment to a diary you can read, as yourself. The entry\'s author is notified, as is everyone who has already commented on it. Comments cannot be edited afterwards.')]
class PostDiaryCommentTool extends DiaryTool
{
    public function handle(Request $request, ShowDiary $show, CreateComment $create): Response|ResponseFactory
    {
        $member = $this->member($request);

        if (! $member->tokenCan(McpAbilities::WRITE)) {
            return Response::error(self::MISSING_WRITE);
        }

        /** @var array{diary_id: int, body: string} $validated */
        $validated = Validator::make(
            [
                'diary_id' => $request->get('diary_id'),
                // Both ends, which is what TrimStrings and OpenPNE 3's own rtrim
                // (opValidatorString) together leave the web form storing.
                'body' => self::trimmed($request, 'body'),
            ],
            [
                'diary_id' => ['required', 'integer', 'min:1'],
                'body' => ['required', 'string', new MaxBytes(self::BODY_MAX_BYTES)],
            ],
        )->validate();

        // Commenting requires viewing the entry, so the gate is the one the web surface reuses too
        // (DiaryCommentController): whoever may read it may answer it.
        $diary = $show($member, (int) $validated['diary_id']);
        if ($diary === null) {
            return $this->refused();
        }

        $comment = $create($member, $diary, $validated['body']);
        $comment->setRelation('member', $member);

        return Response::structured(['comment' => McpDiarySerializer::comment($comment)]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'diary_id' => $schema->integer()->min(1)->required()
                ->description('The diary to comment on, as list-diaries reports it in diaryId.'),
            'body' => $schema->string()->required()
                ->description('The comment text, at most '.self::BODY_MAX_BYTES.' bytes — bytes, not characters, so a Japanese one costs about three each.'),
        ];
    }
}
