<?php

namespace App\Features\GroupTalk\Serializers;

use App\Features\GroupTalk\TalkRoom;
use App\Support\ChatPreview;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TalkRoomSerializer
{
    /**
     * @return array{id: int, name: string, imageUrl: string|null, unread: int, muted: bool, latest: array{body: string, authorName: string|null, authorIsAi: bool, createdAt: string}|null}
     */
    public static function room(TalkRoom $room): array
    {
        $latest = $room->latest;

        return [
            'id' => $room->group->getKey(),
            'name' => $room->group->name,
            'imageUrl' => $room->group->image?->thumbnailUrl(180, 180, square: true),
            'unread' => $room->unread,
            'muted' => $room->muted,
            'latest' => $latest === null ? null : [
                // The room query's `images_exists` rides along, so a message with nothing but
                // pictures says so rather than leaving the row trailing into nothing.
                'body' => ChatPreview::lineOrImages([$latest->body], (bool) $latest->images_exists),
                // The preview carries no member reference, so the AI fact travels beside the name
                // rather than inside it.
                'authorName' => $latest->author?->name,
                'authorIsAi' => (bool) $latest->author?->isAiAccount(),
                'createdAt' => $latest->created_at->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, TalkRoom>  $paginator
     * @return array{data: list<array>, meta: array{currentPage: int, lastPage: int, perPage: int, total: int}}
     */
    public static function paginator(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map([self::class, 'room'], $paginator->items()),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
