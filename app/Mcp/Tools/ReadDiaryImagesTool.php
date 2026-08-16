<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Features\Diary\Queries\ShowDiary;
use App\Files\ImageCache;
use App\Mcp\Tools\Concerns\AnswersWithImages;
use App\Models\Diary;
use App\Models\DiaryCommentImage;
use App\Models\DiaryImage;
use App\Models\File;
use App\Models\Member;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('read-diary-images')]
#[Title('Read the pictures on a diary')]
#[Description('The pictures attached to a diary entry, or to one of its comments, as image data. read-diary says how many an entry and each of its comments carry, in imageCount.')]
#[IsReadOnly]
class ReadDiaryImagesTool extends DiaryTool
{
    use AnswersWithImages;

    public function handle(Request $request, ShowDiary $show, ImageCache $cache): Response|ResponseFactory
    {
        $validated = $request->validate([
            'diary_id' => ['required', 'integer', 'min:1'],
            'comment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'size' => ['sometimes', 'string', 'in:'.implode(',', self::SIZES)],
            'number' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $member = $this->member($request);

        // The entry's own gate first, and nothing about a comment is looked up before it passes.
        $diary = $show($member, (int) $validated['diary_id']);
        if ($diary === null) {
            return $this->refused();
        }

        $transform = $this->transformFor($validated['size'] ?? null);
        if ($transform === null) {
            return Response::error(self::NO_THUMBNAIL);
        }

        $number = isset($validated['number']) ? (int) $validated['number'] : null;

        $targets = isset($validated['comment_id'])
            ? $this->commentTargets($member, $diary, (int) $validated['comment_id'], $number)
            : $this->diaryTargets($member, $diary, $number);

        return $targets instanceof Response ? $targets : $this->answerWithImages($cache, $transform, $targets);
    }

    /**
     * The entry's own pictures, by slot — `DiaryImage.number`, the number the compose form attached
     * them in, and the same meaning talk's slots carry.
     *
     * @return Response|list<array{0: int, 1: File}>
     */
    private function diaryTargets(Member $member, Diary $diary, ?int $number): Response|array
    {
        $rows = $diary->images()->with('file')->get()
            ->map(fn (DiaryImage $image): array => [(int) $image->number, $image->file])
            ->all();

        return $this->chosen($member, $rows, $number);
    }

    /**
     * One comment's pictures. The comment is looked up **within the entry** the caller has just
     * passed the gate on, so a comment of another entry — readable or not — is no more
     * distinguishable here than an id that names nothing.
     *
     * A comment image carries no slot column (OpenPNE 3 has none), so `number` is a position in the
     * comment's own order rather than a stored number, and never a row id. The position is counted
     * over every row the comment has, before any of them is judged, so what a row turned out to hold
     * cannot shift the numbering — or be read off it.
     *
     * @return Response|list<array{0: int, 1: File}>
     */
    private function commentTargets(Member $member, Diary $diary, int $commentId, ?int $number): Response|array
    {
        $comment = $diary->comments()->whereKey($commentId)->first();
        if ($comment === null) {
            return $this->refused();
        }

        $rows = $comment->images()->with('file')->get()
            ->values()
            ->map(fn (DiaryCommentImage $image, int $index): array => [$index + 1, $image->file])
            ->all();

        return $this->chosen($member, $rows, $number);
    }

    /**
     * The pictures to answer with, in number order, or the refusal that stands for all of them.
     *
     * A named number holding no picture — nothing there, not an image, its file gone — is refused
     * rather than answered empty, so the numbers cannot be walked to count what an entry or a
     * comment carries. With no number those same rows are simply passed over: what was asked for is
     * the pictures, and a row that is not one is not missing from them.
     *
     * @param  list<array{0: int, 1: File|null}>  $rows
     * @return Response|list<array{0: int, 1: File}>
     */
    private function chosen(Member $member, array $rows, ?int $number): Response|array
    {
        $targets = [];

        foreach ($rows as [$position, $file]) {
            if ($number !== null && $position !== $number) {
                continue;
            }

            // Belt. FilePolicy resolves a diary or comment image through DiaryAccess::canView — the
            // gate the entry was already resolved through — so it cannot deny one picture of an entry
            // the caller may read. Asked anyway, and a denial takes the whole answer rather than
            // dropping a picture from it.
            if ($file !== null && ! Gate::forUser($member)->allows('view', $file)) {
                return $this->refused();
            }

            if ($file === null || $file->imageFormat() === null) {
                if ($number !== null) {
                    return $this->refused();
                }

                continue;
            }

            $targets[] = [$position, $file];
        }

        return $number !== null && $targets === [] ? $this->refused() : $targets;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'diary_id' => $schema->integer()->min(1)->required()
                ->description('The entry to look at, as list-diaries reports it in diaryId.'),
            'comment_id' => $schema->integer()->min(1)
                ->description('A comment of that entry, as read-diary reports it in comments[].id: its pictures rather than the entry\'s own. Omit it for the entry\'s.'),
            'size' => $schema->string()->enum(self::SIZES)->default('thumbnail')
                ->description('thumbnail: fitted into a 640px box, which is enough to see what a picture is and a fraction of the context the original costs. original: the bytes as they were uploaded — ask for it only when the detail decides something.'),
            'number' => $schema->integer()->min(1)
                ->description('One picture rather than all of them. On the entry it is the slot the picture was attached in; on a comment, whose pictures have no slots, it is their position in the comment, counting from 1. Omit it for every picture, which is also the way to learn the numbers.'),
        ];
    }
}
