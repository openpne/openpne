<?php

namespace App\Features\CommunityEvent\Serializers;

use App\Features\CommunityEvent\CommunityEventAccess;
use App\Features\CommunityEvent\CommunityEventCommentThread;
use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Models\CommunityEventCommentImage;
use App\Models\CommunityEventImage;
use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Modern surface shapes for community events. author is null for a withdrawn member; comment
 * `deletable` is the viewer-specific permission, computed server-side. Dates are ISO strings (the
 * client formats them). RSVP state (isParticipant/isClosed/isExpired/isFull/canParticipate) is a
 * top-level controller prop, not part of these shapes.
 */
class CommunityEventSerializer
{
    /**
     * A board row / recent-events card: the title, comment count, author, last-activity time, and
     * the open date (shown alongside the title). Callers eager-load comments_count and member.
     *
     * openDate is a date-only Y-m-d string, not an ISO datetime: rendering an ISO midnight with the
     * browser's timezone would shift the date a day west of UTC (Classic renders the stored date).
     *
     * @return array{id: int, name: string, commentCount: int, author: array{id: int, name: string, imageUrl: string|null}|null, updatedAt: string, openDate: string}
     */
    public static function summary(CommunityEvent $event): array
    {
        return [
            'id' => $event->getKey(),
            'name' => $event->name,
            'commentCount' => $event->comments_count ?? $event->loadCount('comments')->comments_count,
            'author' => self::author($event->member),
            'updatedAt' => $event->updated_at->toIso8601String(),
            'openDate' => $event->open_date->format('Y-m-d'),
        ];
    }

    /**
     * The event show shape: the full body, images, and the event schedule fields. participantCount is
     * the current roster size (the RSVP button state is computed by the controller). openDate and
     * applicationDeadline are date-only Y-m-d strings (see summary()); createdAt is a real datetime.
     *
     * @return array{id: int, name: string, body: string, images: list<array{id: int, url: string, thumbnailUrl: string}>, author: array{id: int, name: string, imageUrl: string|null}|null, createdAt: string, openDate: string, openDateComment: string, area: string, applicationDeadline: string|null, capacity: int|null, participantCount: int}
     */
    public static function detail(CommunityEvent $event): array
    {
        return [
            'id' => $event->getKey(),
            'name' => $event->name,
            'body' => $event->body,
            'images' => $event->images->map([self::class, 'image'])->all(),
            'author' => self::author($event->member),
            'createdAt' => $event->created_at->toIso8601String(),
            'openDate' => $event->open_date->format('Y-m-d'),
            'openDateComment' => $event->open_date_comment ?? '',
            'area' => $event->area ?? '',
            'applicationDeadline' => $event->application_deadline?->format('Y-m-d'),
            'capacity' => $event->capacity,
            'participantCount' => $event->participantCount(),
        ];
    }

    /**
     * @return array{id: int, number: int, body: string, images: list<array{id: int, url: string, thumbnailUrl: string}>, author: array{id: int, name: string, imageUrl: string|null}|null, createdAt: string, deletable: bool}
     */
    public static function comment(CommunityEventComment $comment, Member $viewer): array
    {
        return [
            'id' => $comment->getKey(),
            'number' => $comment->number,
            'body' => $comment->body,
            'images' => $comment->images->map([self::class, 'image'])->all(),
            'author' => self::author($comment->member),
            'createdAt' => $comment->created_at->toIso8601String(),
            'deletable' => CommunityEventAccess::canDeleteComment($comment, $viewer),
        ];
    }

    /**
     * @param  Collection<int, CommunityEventComment>  $comments
     * @return list<array>
     */
    public static function comments(Collection $comments, Member $viewer): array
    {
        return $comments->map(fn (CommunityEventComment $comment): array => self::comment($comment, $viewer))->all();
    }

    /**
     * The paged comment thread (id-ordered, size 20, reversible) plus the paging state the React page
     * needs — same contract as the topic thread.
     *
     * @return array{comments: list<array>, total: int, page: int, lastPage: int, ascending: bool, hasOlder: bool, hasNewer: bool, olderPage: int|null, newerPage: int|null}
     */
    public static function thread(CommunityEventCommentThread $thread, Member $viewer): array
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
     * A single attached image (event or comment): the full-bytes url and a square thumbnail, both
     * FilePolicy-gated. Tolerates a row whose File is gone (defensive; the join cascades with it).
     *
     * @return array{id: int, url: string, thumbnailUrl: string}
     */
    public static function image(CommunityEventImage|CommunityEventCommentImage $image): array
    {
        $file = $image->file;

        return [
            'id' => $image->getKey(),
            'url' => $file?->url() ?? '',
            'thumbnailUrl' => $file?->thumbnailUrl(120, 120, square: true) ?? '',
        ];
    }

    /**
     * A roster member (event participant list). Requires avatar.file to be loaded so a list is not an
     * N+1.
     *
     * @return array{id: int, name: string, imageUrl: string|null}
     */
    public static function participant(Member $member): array
    {
        return [
            'id' => $member->getKey(),
            'name' => $member->name,
            'imageUrl' => $member->avatar?->file?->thumbnailUrl(76, 76, square: true),
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, Member>  $paginator
     * @return array{data: list<array>, meta: array{currentPage: int, lastPage: int, perPage: int, total: int}}
     */
    public static function participantPaginator(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map([self::class, 'participant'], $paginator->items()),
            'meta' => self::meta($paginator),
        ];
    }

    /**
     * @param  iterable<CommunityEvent>  $events
     * @return list<array>
     */
    public static function summaries(iterable $events): array
    {
        $rows = [];
        foreach ($events as $event) {
            $rows[] = self::summary($event);
        }

        return $rows;
    }

    /**
     * @param  LengthAwarePaginator<int, CommunityEvent>  $paginator
     * @return array{data: list<array>, meta: array{currentPage: int, lastPage: int, perPage: int, total: int}}
     */
    public static function paginator(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map([self::class, 'summary'], $paginator->items()),
            'meta' => self::meta($paginator),
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

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @return array{currentPage: int, lastPage: int, perPage: int, total: int}
     */
    private static function meta(LengthAwarePaginator $paginator): array
    {
        return [
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
