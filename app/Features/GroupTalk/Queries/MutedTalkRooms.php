<?php

declare(strict_types=1);

namespace App\Features\GroupTalk\Queries;

use App\Models\Member;
use App\Support\Feature;
use Illuminate\Support\Facades\DB;

/**
 * Empty while the unit is off: the flag survives the switch, but a room with no talk screen to open
 * is not an exception to offer.
 */
class MutedTalkRooms
{
    /** @return list<array{id: int, name: string}> */
    public function __invoke(Member $viewer): array
    {
        if (! Feature::GroupTalk->enabled()) {
            return [];
        }

        return DB::table('group_members')
            ->join('groups', 'groups.id', '=', 'group_members.group_id')
            ->where('group_members.member_id', (int) $viewer->getKey())
            ->where('group_members.is_talk_muted', true)
            // By name rather than by activity, with the id breaking ties so the order is stable
            // across engines.
            ->orderBy('groups.name')
            ->orderBy('groups.id')
            ->get(['groups.id', 'groups.name'])
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();
    }
}
