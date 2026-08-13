<?php

namespace App\Features\GroupTalk\Serializers;

use App\Features\GroupTalk\TalkRoom;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * Modern surface shape for the room list. `id` is the group's — the row opens its talk, and the
 * group is the only thing a room is. `latest` is null while nothing has been said, which is the
 * seam the client renders "No messages yet." on.
 */
class TalkRoomSerializer
{
    /**
     * How much of a body travels. The row shows one line and clips it in CSS, so this is a payload
     * bound, not the visible truncation — twenty 5,000-code-point bodies per page is what it exists
     * to stop. Nothing is appended: the ellipsis belongs to the clip.
     */
    private const PREVIEW_LIMIT = 140;

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
                'body' => self::preview($latest->body),
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

    /** One line: every run of whitespace becomes a single space, so a multi-line body cannot grow the row. */
    private static function preview(string $body): string
    {
        return Str::limit(trim(preg_replace('/\s+/u', ' ', $body) ?? $body), self::PREVIEW_LIMIT, '');
    }
}
