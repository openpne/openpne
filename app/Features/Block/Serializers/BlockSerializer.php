<?php

namespace App\Features\Block\Serializers;

use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Modern surface shapes for the Block feature. Member models go through here
 * so the columns crossing the network stay explicit rather than leaking via
 * Eloquent `toArray()`.
 */
class BlockSerializer
{
    /** @return array{id: int, name: string, imageUrl: string|null, avatarColor: string|null} */
    public static function member(Member $member): array
    {
        return [
            'id' => $member->getKey(),
            'name' => $member->name,
            'imageUrl' => $member->avatar?->file?->thumbnailUrl(76, 76, square: true),
            'avatarColor' => $member->avatar_color?->hex(),
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, Member>  $paginator
     * @return array{data: list<array{id: int, name: string, imageUrl: string|null, avatarColor: string|null}>, meta: array{currentPage: int, lastPage: int, perPage: int, total: int}}
     */
    public static function paginator(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map([self::class, 'member'], $paginator->items()),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
