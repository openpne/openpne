<?php

namespace App\Features\Diary\Serializers;

use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\DiaryCommentImage;
use App\Models\DiaryImage;
use App\Models\Member;
use App\Support\BodyText;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Modern surface shapes for Diary feature. visibility is always a string slug
 * (never raw int) to avoid JS falsy-zero bugs with Open=0.
 */
class DiarySerializer
{
    /**
     * @return array{id: int, title: string, excerpt: string, visibility: string, commentCount: int, hasImages: bool, thumbnailUrl: string|null, author: array{id: int, name: string, imageUrl: string|null, avatarColor: string|null}, createdAt: string}
     */
    public static function summary(Diary $diary): array
    {
        return [
            'id' => $diary->getKey(),
            'title' => $diary->title,
            'excerpt' => BodyText::excerpt($diary->body),
            'visibility' => $diary->visibility->slug(),
            // List/feed callers eager-load the counts; a single route-bound diary lazy-loads them
            // here so the values are never silently zero.
            'commentCount' => $diary->comments_count ?? $diary->loadCount('comments')->comments_count,
            // The feed shows only a has-photos marker, so the summary carries the boolean,
            // not the images themselves.
            'hasImages' => ($diary->images_count ?? $diary->loadCount('images')->images_count) > 0,
            // Rich rows eager-load firstImage.file; a caller that didn't (dashboard digests) leaves
            // it unloaded, and we return null rather than firing a query per row.
            'thumbnailUrl' => $diary->relationLoaded('firstImage') ? $diary->firstImage?->file?->thumbnailUrl(120, 120, square: true) : null,
            'author' => [
                'id' => $diary->member->getKey(),
                'name' => $diary->member->name,
                'imageUrl' => $diary->member->avatar?->file?->thumbnailUrl(76, 76, square: true),
                'avatarColor' => $diary->member->avatar_color?->hex(),
            ],
            'createdAt' => $diary->created_at->toIso8601String(),
        ];
    }

    /**
     * detail is a superset of summary (the React DiaryDetail extends DiarySummary): it carries the
     * full images plus hasImages, so a caller typed on either shape reads consistent data.
     *
     * @return array{id: int, title: string, excerpt: string, body: string, visibility: string, commentCount: int, hasImages: bool, thumbnailUrl: string|null, images: list<array{id: int, url: string, thumbnailUrl: string}>, author: array{id: int, name: string, imageUrl: string|null, avatarColor: string|null}, createdAt: string}
     */
    public static function detail(Diary $diary): array
    {
        $images = $diary->images->map([self::class, 'image'])->all();

        return [
            'id' => $diary->getKey(),
            'title' => $diary->title,
            'excerpt' => BodyText::excerpt($diary->body),
            'body' => $diary->body,
            'visibility' => $diary->visibility->slug(),
            'commentCount' => $diary->comments_count ?? $diary->loadCount('comments')->comments_count,
            'hasImages' => $images !== [],
            // detail eager-loads images (number-ordered); the first is the same thumbnail the list
            // rows derive from firstImage, without a second one-of-many load.
            'thumbnailUrl' => $diary->images->first()?->file?->thumbnailUrl(120, 120, square: true),
            'images' => $images,
            'author' => [
                'id' => $diary->member->getKey(),
                'name' => $diary->member->name,
                'imageUrl' => $diary->member->avatar?->file?->thumbnailUrl(76, 76, square: true),
                'avatarColor' => $diary->member->avatar_color?->hex(),
            ],
            'createdAt' => $diary->created_at->toIso8601String(),
        ];
    }

    /**
     * The older/newer pager needs only identity, title, and date; null-transparent so a caller can
     * forward a missing neighbour straight through. createdAt matches detail()/summary().
     *
     * @return array{id: int, title: string, createdAt: string}|null
     */
    public static function neighbor(?Diary $diary): ?array
    {
        if ($diary === null) {
            return null;
        }

        return [
            'id' => $diary->getKey(),
            'title' => $diary->title,
            'createdAt' => $diary->created_at->toIso8601String(),
        ];
    }

    /**
     * A single attached image (diary or comment): the full-bytes url and a square thumbnail, both
     * FilePolicy-gated. Tolerates a row whose File is gone (defensive; the join cascades with it).
     *
     * @return array{id: int, url: string, thumbnailUrl: string}
     */
    public static function image(DiaryImage|DiaryCommentImage $image): array
    {
        $file = $image->file;

        return [
            'id' => $image->getKey(),
            'url' => $file?->url() ?? '',
            'thumbnailUrl' => $file?->thumbnailUrl(120, 120, square: true) ?? '',
        ];
    }

    /**
     * `author` is null for a withdrawn member; `deletable` is the viewer-specific delete
     * permission, computed server-side so the client never re-derives authorization.
     *
     * @return array{id: int, number: int, body: string, images: list<array{id: int, url: string, thumbnailUrl: string}>, author: array{id: int, name: string}|null, createdAt: string, deletable: bool}
     */
    public static function comment(DiaryComment $comment, Member $viewer): array
    {
        return [
            'id' => $comment->getKey(),
            'number' => $comment->number,
            'body' => $comment->body,
            'images' => $comment->images->map([self::class, 'image'])->all(),
            'author' => $comment->member ? [
                'id' => $comment->member->getKey(),
                'name' => $comment->member->name,
            ] : null,
            'createdAt' => $comment->created_at->toIso8601String(),
            'deletable' => $comment->isDeletableBy($viewer),
        ];
    }

    /**
     * @param  Collection<int, DiaryComment>  $comments
     * @return list<array>
     */
    public static function comments(Collection $comments, Member $viewer): array
    {
        return $comments->map(fn (DiaryComment $comment): array => self::comment($comment, $viewer))->all();
    }

    /**
     * @param  LengthAwarePaginator<int, Diary>  $paginator
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
}
