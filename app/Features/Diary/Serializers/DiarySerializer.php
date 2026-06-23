<?php

namespace App\Features\Diary\Serializers;

use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\DiaryImage;
use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Modern surface shapes for Diary feature. visibility is always a string slug
 * (never raw int) to avoid JS falsy-zero bugs with Open=0.
 */
class DiarySerializer
{
    /**
     * @return array{id: int, title: string, visibility: string, commentCount: int, hasImages: bool, author: array{id: int, name: string}, createdAt: string}
     */
    public static function summary(Diary $diary): array
    {
        return [
            'id' => $diary->getKey(),
            'title' => $diary->title,
            'visibility' => $diary->visibility->slug(),
            // List/feed callers eager-load the counts; a single route-bound diary lazy-loads them
            // here so the values are never silently zero.
            'commentCount' => $diary->comments_count ?? $diary->loadCount('comments')->comments_count,
            // The feed shows only a has-photos marker (OpenPNE 3 op_diary_image_icon), so the
            // summary carries the boolean, not the images themselves.
            'hasImages' => ($diary->images_count ?? $diary->loadCount('images')->images_count) > 0,
            'author' => [
                'id' => $diary->member->getKey(),
                'name' => $diary->member->name,
            ],
            'createdAt' => $diary->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array{id: int, title: string, body: string, visibility: string, images: list<array{id: int, url: string, thumbnailUrl: string}>, author: array{id: int, name: string}, createdAt: string}
     */
    public static function detail(Diary $diary): array
    {
        return [
            'id' => $diary->getKey(),
            'title' => $diary->title,
            'body' => $diary->body,
            'visibility' => $diary->visibility->slug(),
            'images' => $diary->images->map([self::class, 'image'])->all(),
            'author' => [
                'id' => $diary->member->getKey(),
                'name' => $diary->member->name,
            ],
            'createdAt' => $diary->created_at->toIso8601String(),
        ];
    }

    /**
     * A single attached image: the full-bytes url and a square thumbnail, both FilePolicy-gated.
     * Skips a number-only row whose File is gone (defensive; the join cascades with the File).
     *
     * @return array{id: int, url: string, thumbnailUrl: string}
     */
    public static function image(DiaryImage $image): array
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
     * @return array{id: int, number: int, body: string, author: array{id: int, name: string}|null, createdAt: string, deletable: bool}
     */
    public static function comment(DiaryComment $comment, Member $viewer): array
    {
        return [
            'id' => $comment->getKey(),
            'number' => $comment->number,
            'body' => $comment->body,
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
