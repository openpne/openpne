<?php

namespace App\Features\GroupTalk\Queries;

use App\Models\Group;
use App\Models\Member;

/**
 * No preview is drawn, so the leading message and its author are never hydrated — which is what
 * makes this affordable on a prop every page evaluates.
 */
class NavTalkRooms
{
    public const LIMIT = 10;

    public function __construct(private readonly JoinedTalkRooms $rooms) {}

    /**
     * @return array{rooms: list<array{id: int, name: string, imageUrl: string|null, unread: int, muted: bool}>, hasMore: bool}
     */
    public function __invoke(Member $viewer, int $limit = self::LIMIT): array
    {
        $rows = $this->rooms->ordered($viewer)->limit($limit + 1)->get();

        return [
            'rooms' => $rows->take($limit)->map(fn (Group $group): array => [
                'id' => $group->getKey(),
                'name' => $group->name,
                // A sidebar row paints its image at 24px, so 48 is the whitelisted size that covers
                // it at 2x — the rail's 180 would be far more bytes for the same row.
                'imageUrl' => $group->image?->thumbnailUrl(48, 48, square: true),
                'unread' => (int) $group->unread_talk_count,
                'muted' => (bool) $group->is_talk_muted,
            ])->values()->all(),
            'hasMore' => $rows->count() > $limit,
        ];
    }
}
