<?php

namespace App\Features\GroupTopic\Serializers;

use App\Features\GroupTopic\GroupTopicAccess;
use App\Features\GroupTopic\GroupTopicCommentThread;
use App\Features\Member\Serializers\MemberRefSerializer;
use App\Files\ImageLadder;
use App\LinkCard\LinkCardSerializer;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\GroupTopicCommentImage;
use App\Models\GroupTopicImage;
use App\Models\Member;
use App\Support\BodyFormat;
use App\Support\BodyRenderer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * author is null for a withdrawn member (the FK SET NULL); comment `deletable` is the viewer's
 * permission, computed server-side so the client never re-derives authorization. Dates are ISO
 * strings.
 */
class GroupTopicSerializer
{
    /**
     * Callers eager-load `comments_count` and `member`.
     *
     * @return array{id: int, name: string, commentCount: int, author: array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}|null, bumpedAt: string}
     */
    public static function summary(GroupTopic $topic): array
    {
        return [
            'id' => $topic->getKey(),
            'name' => $topic->name,
            'commentCount' => $topic->comments_count ?? $topic->loadCount('comments')->comments_count,
            'author' => self::author($topic->member),
            'bumpedAt' => $topic->bumped_at->toIso8601String(),
        ];
    }

    /**
     * @return array{id: int, name: string, body: string, format: string, bodyHtml: string|null, images: list<array{id: int, url: string, thumbnailUrl: string, fitSources: list<array{url: string, box: int}>, cropSources: array{tall?: list<array{url: string, width: int}>, wide?: list<array{url: string, width: int}>}, width: int|null, height: int|null, animatedSources: list<array{url: string, box: int}>}>, author: array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}|null, linkCard: array{url: string, title: string, description: string|null, siteName: string|null, domain: string, layout: string, imageUrl: string|null, imageWidth: int|null, imageHeight: int|null, fitSources: list<array{url: string, box: int}>}|null, createdAt: string, editedAt: string|null}
     */
    /** @param  list<array{emoji: string, count: int, mine: bool}>  $reactions  the topic's chips, passed rather than read off the model */
    public static function detail(GroupTopic $topic, Member $viewer, array $reactions): array
    {
        return [
            'id' => $topic->getKey(),
            'name' => $topic->name,
            'body' => $topic->body,
            // bodyHtml is the server-rendered decoration HTML, null for plain
            // (docs/internals/body-text.md, "Render authority is the server").
            'format' => $topic->format->value,
            'bodyHtml' => $topic->format === BodyFormat::Plain ? null : BodyRenderer::render($topic->body, $topic->format)->toHtml(),
            'images' => $topic->images->map([self::class, 'image'])->all(),
            'author' => self::author($topic->member),
            'linkCard' => LinkCardSerializer::card($topic, $viewer),
            'createdAt' => $topic->created_at->toIso8601String(),
            'editedAt' => $topic->edited_at?->toIso8601String(),
            'reactions' => $reactions,
        ];
    }

    /**
     * @param  list<array{emoji: string, count: int, mine: bool}>  $reactions  the row's chips, passed rather than read off the model so a page costs one grouped read
     * @return array{id: int, number: int, body: string, images: list<array{id: int, url: string, thumbnailUrl: string, fitSources: list<array{url: string, box: int}>, cropSources: array{tall?: list<array{url: string, width: int}>, wide?: list<array{url: string, width: int}>}, width: int|null, height: int|null, animatedSources: list<array{url: string, box: int}>}>, linkCard: array{url: string, title: string, description: string|null, siteName: string|null, domain: string, layout: string, imageUrl: string|null, imageWidth: int|null, imageHeight: int|null, fitSources: list<array{url: string, box: int}>}|null, author: array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}|null, createdAt: string, deletable: bool, reactions: list<array{emoji: string, count: int, mine: bool}>}
     */
    public static function comment(GroupTopicComment $comment, Member $viewer, array $reactions): array
    {
        return [
            'id' => $comment->getKey(),
            'number' => $comment->number,
            'body' => $comment->body,
            'images' => $comment->images->map([self::class, 'image'])->all(),
            'linkCard' => LinkCardSerializer::card($comment, $viewer),
            'author' => self::author($comment->member),
            'createdAt' => $comment->created_at->toIso8601String(),
            'deletable' => GroupTopicAccess::canDeleteComment($comment, $viewer),
            'reactions' => $reactions,
        ];
    }

    /**
     * @param  Collection<int, GroupTopicComment>  $comments
     * @param  array<int, list<array{emoji: string, count: int, mine: bool}>>  $reactions  keyed by comment id, as ReactionAggregates answers
     * @return list<array>
     */
    public static function comments(Collection $comments, Member $viewer, array $reactions): array
    {
        return $comments->map(fn (GroupTopicComment $comment): array => self::comment($comment, $viewer, $reactions[(int) $comment->getKey()] ?? []))->all();
    }

    /**
     * @param  array<int, list<array{emoji: string, count: int, mine: bool}>>  $reactions  keyed by comment id
     * @return array{comments: list<array>, total: int, page: int, lastPage: int, ascending: bool, hasOlder: bool, hasNewer: bool, olderPage: int|null, newerPage: int|null}
     */
    public static function thread(GroupTopicCommentThread $thread, Member $viewer, array $reactions): array
    {
        return [
            'comments' => self::comments($thread->comments, $viewer, $reactions),
            'total' => $thread->total,
            'page' => $thread->page,
            'lastPage' => $thread->lastPage,
            'ascending' => $thread->ascending,
            'hasOlder' => $thread->hasOlder(),
            'hasNewer' => $thread->hasNewer(),
            'olderPage' => $thread->hasOlder() ? $thread->olderPage() : null,
            'newerPage' => $thread->hasNewer() ? $thread->newerPage() : null,
        ];
    }

    /**
     * All sources are FilePolicy-gated; which one a surface takes is docs/internals/images.md,
     * "The two ladders". A row whose File is gone is tolerated defensively.
     *
     * @return array{id: int, url: string, thumbnailUrl: string, fitSources: list<array{url: string, box: int}>, cropSources: array{tall?: list<array{url: string, width: int}>, wide?: list<array{url: string, width: int}>}, width: int|null, height: int|null, animatedSources: list<array{url: string, box: int}>}
     */
    public static function image(GroupTopicImage|GroupTopicCommentImage $image): array
    {
        $file = $image->file;

        return [
            'id' => $image->getKey(),
            'url' => $file?->url() ?? '',
            ...ImageLadder::of($file),
        ];
    }

    /**
     * @param  iterable<GroupTopic>  $topics
     * @return list<array>
     */
    public static function summaries(iterable $topics): array
    {
        $rows = [];
        foreach ($topics as $topic) {
            $rows[] = self::summary($topic);
        }

        return $rows;
    }

    /**
     * @param  LengthAwarePaginator<int, GroupTopic>  $paginator
     * @return array{data: list<array>, meta: array{currentPage: int, lastPage: int, perPage: int, total: int}}
     */
    public static function paginator(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map([self::class, 'summary'], $paginator->items()),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /** @return array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}|null */
    private static function author(?Member $member): ?array
    {
        return $member === null ? null : MemberRefSerializer::ref($member);
    }
}
