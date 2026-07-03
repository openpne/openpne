<?php

namespace App\Features\Friend\Serializers;

use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Modern surface shapes for Friend feature. Member models must not cross
 * the network through Eloquent `toArray()`; they go through here so the
 * exposed columns stay explicit.
 */
class FriendSerializer
{
    /** @return array{id: int, name: string, imageUrl: string|null} */
    public static function member(Member $member): array
    {
        return [
            'id' => $member->getKey(),
            'name' => $member->name,
            'imageUrl' => $member->avatar?->file?->thumbnailUrl(76, 76, square: true),
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, Member>  $paginator
     * @return array{data: list<array{id: int, name: string, imageUrl: string|null}>, meta: array{currentPage: int, lastPage: int, perPage: int, total: int}}
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
