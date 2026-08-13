<?php

namespace App\Features\GroupTalk\Queries;

use App\Models\Group;
use App\Models\Member;

/**
 * The nav's slice of the room list: the same rows in the same order as `/groups/mine`, stopping at
 * what a sidebar row draws — image, name, unread, mute. It is deliberately not the room list's read:
 * the nav shows no preview, so it does not hydrate the leading message or its author, and the two
 * queries those cost are the reason this is affordable on every page.
 *
 * `hasMore` comes from reading one row past the limit rather than a `count(*)` over the membership.
 */
class NavTalkRooms
{
    /** Rooms the sidebar holds; past that it hands the reader to the joined list. */
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
