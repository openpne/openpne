<?php

namespace App\Features\Diary\Serializers;

use App\Models\Diary;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Modern surface shapes for Diary feature. visibility is always a string slug
 * (never raw int) to avoid JS falsy-zero bugs with Open=0.
 */
class DiarySerializer
{
    /**
     * @return array{id: int, title: string, visibility: string, author: array{id: int, name: string}, createdAt: string}
     */
    public static function summary(Diary $diary): array
    {
        return [
            'id' => $diary->getKey(),
            'title' => $diary->title,
            'visibility' => $diary->visibility->slug(),
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
