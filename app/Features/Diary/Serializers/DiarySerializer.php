<?php

namespace App\Features\Diary\Serializers;

use App\Models\Diary;
use App\Models\DiaryComment;
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
     * @return array{id: int, title: string, visibility: string, commentCount: int, author: array{id: int, name: string}, createdAt: string}
     */
    public static function summary(Diary $diary): array
    {
        return [
            'id' => $diary->getKey(),
            'title' => $diary->title,
            'visibility' => $diary->visibility->slug(),
            // List/feed callers eager-load the count; a single route-bound diary lazy-loads it here
            // so the count is never silently zero.
            'commentCount' => $diary->comments_count ?? $diary->loadCount('comments')->comments_count,
            'author' => [
                'id' => $diary->member->getKey(),
                'name' => $diary->member->name,
            ],
            'createdAt' => $diary->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array{id: int, title: string, body: string, visibility: string, author: array{id: int, name: string}, createdAt: string}
     */
    public static function detail(Diary $diary): array
    {
        return [
            'id' => $diary->getKey(),
            'title' => $diary->title,
            'body' => $diary->body,
            'visibility' => $diary->visibility->slug(),
            'author' => [
                'id' => $diary->member->getKey(),
                'name' => $diary->member->name,
            ],
            'createdAt' => $diary->created_at->toIso8601String(),
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
