<?php

namespace App\Features\GroupTalk\Serializers;

use App\Features\GroupTalk\TalkRoom;
use App\Support\ChatPreview;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Modern surface shape for the room list. `id` is the group's — the row opens its talk, and the
 * group is the only thing a room is. `latest` is null while nothing has been said, which is the
 * seam the client renders "No messages yet." on.
 */
class TalkRoomSerializer
{
    /**
     * @return array{id: int, name: string, imageUrl: string|null, unread: int, muted: bool, latest: array{body: string, authorName: string|null, createdAt: string}|null}
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
                // A message with nothing but pictures says so, rather than leaving the row's
                // "author: " trailing into nothing. JoinedTalkRooms supplies `images_exists`.
                'body' => ChatPreview::lineOrImages([$latest->body], (bool) $latest->images_exists),
                'authorName' => $latest->author?->name,
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
