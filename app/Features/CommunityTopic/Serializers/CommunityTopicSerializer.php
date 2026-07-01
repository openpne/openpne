<?php

namespace App\Features\CommunityTopic\Serializers;

use App\Features\CommunityTopic\CommunityTopicAccess;
use App\Features\CommunityTopic\CommunityTopicCommentThread;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\CommunityTopicCommentImage;
use App\Models\CommunityTopicImage;
use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Modern surface shapes for the community topic board. author is null for a withdrawn member (the
 * FK SET NULL); comment `deletable` is the viewer-specific permission, computed server-side so the
 * client never re-derives authorization. Dates are ISO strings (the client formats them).
 */
class CommunityTopicSerializer
{
    /**
     * A board row / recent-topics card: the title, comment count, author, and last-activity time
     * (updated_at, bumped by a new comment). Callers eager-load `comments_count` and `member`.
     *
     * @return array{id: int, name: string, commentCount: int, author: array{id: int, name: string, imageUrl: string|null}|null, updatedAt: string}
     */
    public static function summary(CommunityTopic $topic): array
    {
        return [
            'id' => $topic->getKey(),
            'name' => $topic->name,
            'commentCount' => $topic->comments_count ?? $topic->loadCount('comments')->comments_count,
            'author' => self::author($topic->member),
            'updatedAt' => $topic->updated_at->toIso8601String(),
        ];
    }

    /**
     * The topic show shape: the full body and images plus the author and post time.
     *
     * @return array{id: int, name: string, body: string, images: list<array{id: int, url: string, thumbnailUrl: string}>, author: array{id: int, name: string, imageUrl: string|null}|null, createdAt: string}
     */
    public static function detail(CommunityTopic $topic): array
    {
        return [
            'id' => $topic->getKey(),
            'name' => $topic->name,
            'body' => $topic->body,
            'images' => $topic->images->map([self::class, 'image'])->all(),
            'author' => self::author($topic->member),
            'createdAt' => $topic->created_at->toIso8601String(),
        ];
    }

    /**
     * A single comment. `deletable` is the viewer's delete permission (its author, or anyone who may
     * edit the topic), so the client renders the button without re-deriving the rule.
     *
     * @return array{id: int, number: int, body: string, images: list<array{id: int, url: string, thumbnailUrl: string}>, author: array{id: int, name: string, imageUrl: string|null}|null, createdAt: string, deletable: bool}
     */
    public static function comment(CommunityTopicComment $comment, Member $viewer): array
    {
        return [
            'id' => $comment->getKey(),
            'number' => $comment->number,
            'body' => $comment->body,
            'images' => $comment->images->map([self::class, 'image'])->all(),
            'author' => self::author($comment->member),
            'createdAt' => $comment->created_at->toIso8601String(),
            'deletable' => CommunityTopicAccess::canDeleteComment($comment, $viewer),
        ];
    }

    /**
     * @param  Collection<int, CommunityTopicComment>  $comments
     * @return list<array>
     */
    public static function comments(Collection $comments, Member $viewer): array
    {
        return $comments->map(fn (CommunityTopicComment $comment): array => self::comment($comment, $viewer))->all();
    }

    /**
     * The paged comment thread (OpenPNE 3 pager): the current page ascending, plus the reversible
     * paging state the React page needs to build Older/Newer/oldest-first links. Ordering is by id,
     * not number (the pager's contract), so Modern matches Classic even on migrated data.
     *
     * @return array{comments: list<array>, total: int, page: int, lastPage: int, ascending: bool, hasOlder: bool, hasNewer: bool, olderPage: int|null, newerPage: int|null}
     */
    public static function thread(CommunityTopicCommentThread $thread, Member $viewer): array
    {
        return [
            'comments' => self::comments($thread->comments, $viewer),
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
     * A single attached image: the full-bytes url and a square thumbnail, both FilePolicy-gated.
     * Tolerates a row whose File is gone (defensive; the join cascades with it).
     *
     * @return array{id: int, url: string, thumbnailUrl: string}
     */
    public static function image(CommunityTopicImage|CommunityTopicCommentImage $image): array
    {
        $file = $image->file;

        return [
            'id' => $image->getKey(),
            'url' => $file?->url() ?? '',
            'thumbnailUrl' => $file?->thumbnailUrl(120, 120, square: true) ?? '',
        ];
    }

    /**
     * @param  iterable<CommunityTopic>  $topics
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
     * @param  LengthAwarePaginator<int, CommunityTopic>  $paginator
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

    /** @return array{id: int, name: string, imageUrl: string|null}|null */
    private static function author(?Member $member): ?array
    {
        if ($member === null) {
            return null;
        }

        return [
            'id' => $member->getKey(),
            'name' => $member->name,
            'imageUrl' => $member->avatar?->file?->thumbnailUrl(76, 76, square: true),
        ];
    }
}
