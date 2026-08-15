<?php

namespace App\Features\GroupEvent\Serializers;

use App\Features\GroupEvent\GroupEventAccess;
use App\Features\GroupEvent\GroupEventCommentThread;
use App\Features\Member\Serializers\MemberRefSerializer;
use App\LinkCard\LinkCardSerializer;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupEventCommentImage;
use App\Models\GroupEventImage;
use App\Models\Member;
use App\Support\BodyFormat;
use App\Support\BodyRenderer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Modern surface shapes for community events. author is null for a withdrawn member; comment
 * `deletable` is the viewer-specific permission, computed server-side. Datetimes are ISO strings;
 * the date-only open_date/application_deadline are Y-m-d (the client formats them). RSVP state
 * (isParticipant/isClosed/isExpired/isFull/canParticipate) is a top-level controller prop, not part
 * of these shapes.
 */
class GroupEventSerializer
{
    /**
     * A board row / recent-events card: the title, comment count, participant count, author,
     * last-activity time, and the open date (shown alongside the title). Callers eager-load
     * comments_count, participants_count, and member.
     *
     * openDate is a date-only Y-m-d string, not an ISO datetime: rendering an ISO midnight with the
     * browser's timezone would shift the date a day west of UTC (Classic renders the stored date).
     *
     * @return array{id: int, name: string, commentCount: int, participantCount: int, author: array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}|null, updatedAt: string, openDate: string}
     */
    public static function summary(GroupEvent $event): array
    {
        return [
            'id' => $event->getKey(),
            'name' => $event->name,
            'commentCount' => $event->comments_count ?? $event->loadCount('comments')->comments_count,
            'participantCount' => $event->participants_count ?? $event->loadCount('participants')->participants_count,
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
     * @return array{id: int, name: string, body: string, format: string, bodyHtml: string|null, images: list<array{id: int, url: string, thumbnailUrl: string, fitSources: list<array{url: string, box: int}>, cropSources: array{tall?: list<array{url: string, width: int}>, wide?: list<array{url: string, width: int}>}, width: int|null, height: int|null}>, author: array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}|null, linkCard: array{url: string, title: string, description: string|null, siteName: string|null, domain: string, imageUrl: string|null}|null, createdAt: string, openDate: string, openDateComment: string, area: string, applicationDeadline: string|null, capacity: int|null, participantCount: int}
     */
    public static function detail(GroupEvent $event): array
    {
        return [
            'id' => $event->getKey(),
            'name' => $event->name,
            'body' => $event->body,
            // See DiarySerializer::detail: bodyHtml is the server-rendered decoration HTML, null for plain.
            'format' => $event->format->value,
            'bodyHtml' => $event->format === BodyFormat::Plain ? null : BodyRenderer::render($event->body, $event->format)->toHtml(),
            'images' => $event->images->map([self::class, 'image'])->all(),
            'author' => self::author($event->member),
            'linkCard' => LinkCardSerializer::card($event),
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
     * @return array{id: int, number: int, body: string, images: list<array{id: int, url: string, thumbnailUrl: string, fitSources: list<array{url: string, box: int}>, cropSources: array{tall?: list<array{url: string, width: int}>, wide?: list<array{url: string, width: int}>}, width: int|null, height: int|null}>, author: array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}|null, createdAt: string, deletable: bool}
     */
    public static function comment(GroupEventComment $comment, Member $viewer): array
    {
        return [
            'id' => $comment->getKey(),
            'number' => $comment->number,
            'body' => $comment->body,
            'images' => $comment->images->map([self::class, 'image'])->all(),
            'author' => self::author($comment->member),
            'createdAt' => $comment->created_at->toIso8601String(),
            'deletable' => GroupEventAccess::canDeleteComment($comment, $viewer),
        ];
    }

    /**
     * @param  Collection<int, GroupEventComment>  $comments
     * @return list<array>
     */
    public static function comments(Collection $comments, Member $viewer): array
    {
        return $comments->map(fn (GroupEventComment $comment): array => self::comment($comment, $viewer))->all();
    }

    /**
     * The paged comment thread (id-ordered, size 20, reversible) plus the paging state the React page
     * needs — same contract as the topic thread.
     *
     * @return array{comments: list<array>, total: int, page: int, lastPage: int, ascending: bool, hasOlder: bool, hasNewer: bool, olderPage: int|null, newerPage: int|null}
     */
    public static function thread(GroupEventCommentThread $thread, Member $viewer): array
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
     * A single attached image (event or comment): the full-bytes url plus the thumbnail sources a
     * surface picks from, all FilePolicy-gated. See docs/internals/images.md for which one a
     * surface takes and why the intrinsic size travels with them. Tolerates a row whose File is
     * gone (defensive; the join cascades with it).
     *
     * @return array{id: int, url: string, thumbnailUrl: string, fitSources: list<array{url: string, box: int}>, cropSources: array{tall?: list<array{url: string, width: int}>, wide?: list<array{url: string, width: int}>}, width: int|null, height: int|null}
     */
    public static function image(GroupEventImage|GroupEventCommentImage $image): array
    {
        $file = $image->file;

        return [
            'id' => $image->getKey(),
            'url' => $file?->url() ?? '',
            'thumbnailUrl' => $file?->thumbnailUrl(120, 120, square: true) ?? '',
            'fitSources' => $file ? [
                ['url' => $file->thumbnailUrl(320, 320), 'box' => 320],
                ['url' => $file->thumbnailUrl(640, 640), 'box' => 640],
                ['url' => $file->thumbnailUrl(1200, 1200), 'box' => 1200],
            ] : [],
            'cropSources' => $file ? [
                'tall' => [
                    ['url' => $file->thumbnailUrl(300, 400, square: true), 'width' => 300],
                    ['url' => $file->thumbnailUrl(600, 800, square: true), 'width' => 600],
                ],
                'wide' => [
                    ['url' => $file->thumbnailUrl(300, 200, square: true), 'width' => 300],
                    ['url' => $file->thumbnailUrl(600, 400, square: true), 'width' => 600],
                ],
            ] : [],
            'width' => $file?->width,
            'height' => $file?->height,
        ];
    }

    /**
     * A roster member (event participant list). Requires avatar.file to be loaded so a list is not an
     * N+1.
     *
     * @return array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}
     */
    public static function participant(Member $member): array
    {
        return MemberRefSerializer::ref($member);
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
     * @param  iterable<GroupEvent>  $events
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
     * @param  LengthAwarePaginator<int, GroupEvent>  $paginator
     * @return array{data: list<array>, meta: array{currentPage: int, lastPage: int, perPage: int, total: int}}
     */
    public static function paginator(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map([self::class, 'summary'], $paginator->items()),
            'meta' => self::meta($paginator),
        ];
    }

    /** @return array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}|null */
    private static function author(?Member $member): ?array
    {
        return $member === null ? null : MemberRefSerializer::ref($member);
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
