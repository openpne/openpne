<?php

namespace App\Features\Block\Serializers;

use App\Features\Member\Serializers\MemberRefSerializer;
use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class BlockSerializer
{
    /** @return array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool} */
    public static function member(Member $member): array
    {
        return MemberRefSerializer::ref($member);
    }

    /**
     * @param  LengthAwarePaginator<int, Member>  $paginator
     * @return array{data: list<array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}>, meta: array{currentPage: int, lastPage: int, perPage: int, total: int}}
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
