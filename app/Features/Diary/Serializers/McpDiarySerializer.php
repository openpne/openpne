<?php

declare(strict_types=1);

namespace App\Features\Diary\Serializers;

use App\Features\Member\Serializers\MemberRefSerializer;
use App\Mcp\Tools\ReadDiaryImagesTool;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Support\BodyRenderer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * What the MCP diary tools put on the wire (docs/internals/mcp.md, "Diaries"). A separate shape from
 * {@see DiarySerializer} rather than a reuse of it: that one carries `/file` and `/cache/img` URLs,
 * which are session-guarded and so always 404 for a bearer client.
 */
class McpDiarySerializer
{
    /**
     * @return array{diaryId: int, title: string, excerpt: string, visibility: string, commentCount: int, imageCount: int, authorId: int, authorName: string, authorIsAi: bool, createdAt: string}
     */
    public static function summary(Diary $diary): array
    {
        return [
            'diaryId' => (int) $diary->getKey(),
            'title' => $diary->title,
            'excerpt' => BodyRenderer::excerpt($diary->body, $diary->format),
            // A slug, never the stored int: Open is 0, and a raw 0 reads as "no audience".
            'visibility' => $diary->visibility->slug(),
            ...self::counts($diary),
            ...self::author($diary),
            'createdAt' => $diary->created_at->toIso8601String(),
        ];
    }

    /**
     * The author is never null here: a diary's `member_id` is not nullable, and withdrawal takes the
     * entries with the account.
     *
     * @param  Collection<int, DiaryComment>  $comments
     * @return array{diaryId: int, title: string, body: string, visibility: string, commentCount: int, imageCount: int, authorId: int, authorName: string, authorIsAi: bool, createdAt: string, comments: list<array<string, mixed>>}
     */
    public static function detail(Diary $diary, Collection $comments): array
    {
        return [
            'diaryId' => (int) $diary->getKey(),
            'title' => $diary->title,
            'body' => BodyRenderer::plainText($diary->body, $diary->format),
            'visibility' => $diary->visibility->slug(),
            ...self::counts($diary),
            ...self::author($diary),
            'createdAt' => $diary->created_at->toIso8601String(),
            'comments' => $comments->map([self::class, 'comment'])->values()->all(),
        ];
    }

    /**
     * A comment carries no format column — OpenPNE 3 has none either — so its body is already the
     * plain text it is stored as. Its pictures are counted here and read by naming the comment
     * ({@see ReadDiaryImagesTool}).
     *
     * @return array{id: int, number: int, body: string, imageCount: int, authorId: int|null, authorName: string|null, authorIsAi: bool|null, createdAt: string}
     */
    public static function comment(DiaryComment $comment): array
    {
        $author = $comment->member;

        return [
            'id' => (int) $comment->getKey(),
            'number' => (int) $comment->number,
            'body' => $comment->body,
            'imageCount' => (int) ($comment->images_count ?? $comment->loadCount('images')->images_count),
            // Null for a withdrawn author (the FK sets it null), which is a fact about the row rather
            // than a gap to paper over — including `authorIsAi`, since there is no account left to be one.
            'authorId' => $author?->getKey(),
            'authorName' => $author?->name,
            'authorIsAi' => $author === null ? null : $author->isAiAccount(),
            'createdAt' => $comment->created_at->toIso8601String(),
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, Diary>  $paginator
     * @return array{diaries: list<array<string, mixed>>, page: int, lastPage: int, total: int}
     */
    public static function diaries(LengthAwarePaginator $paginator): array
    {
        return [
            'diaries' => array_map([self::class, 'summary'], $paginator->items()),
            'page' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * The feed eager-loads both counts; a single freshly written entry has neither, and loads them
     * rather than reporting a silent zero.
     *
     * @return array{commentCount: int, imageCount: int}
     */
    private static function counts(Diary $diary): array
    {
        return [
            'commentCount' => (int) ($diary->comments_count ?? $diary->loadCount('comments')->comments_count),
            'imageCount' => (int) ($diary->images_count ?? $diary->loadCount('images')->images_count),
        ];
    }

    /** @return array{authorId: int, authorName: string, authorIsAi: bool} */
    private static function author(Diary $diary): array
    {
        $author = MemberRefSerializer::ref($diary->member);

        return [
            'authorId' => (int) $author['id'],
            'authorName' => $author['name'],
            'authorIsAi' => $author['isAi'],
        ];
    }
}
