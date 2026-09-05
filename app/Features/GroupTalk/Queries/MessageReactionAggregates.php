<?php

namespace App\Features\GroupTalk\Queries;

use App\Models\GroupMessage;
use App\Models\Member;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Counted in SQL rather than off a loaded relation: the rows behind a chip row are one per reactor
 * per emoji. Groups are ordered by their earliest row rather than by count, so the chips read in the
 * order the emoji first appeared on the message.
 */
class MessageReactionAggregates
{
    /**
     * @param  Collection<int, GroupMessage>  $messages
     * @return array<int, list<array{emoji: string, count: int, mine: bool}>> keyed by message id; a
     *                                                                        message nobody reacted to has no key at all
     */
    public function __invoke(Member $viewer, Collection $messages): array
    {
        $ids = $messages->map(fn (GroupMessage $message): int => (int) $message->getKey())->all();

        if ($ids === []) {
            return [];
        }

        $rows = DB::table('reactions')
            ->where('reactable_type', (new GroupMessage)->getMorphClass())
            ->whereIn('reactable_id', $ids)
            ->select('reactable_id', 'emoji')
            ->selectRaw('count(*) as total')
            ->selectRaw('max(case when member_id = ? then 1 else 0 end) as mine', [(int) $viewer->getKey()])
            ->groupBy('reactable_id', 'emoji')
            ->orderBy('reactable_id')
            ->orderByRaw('min(created_at)')
            ->orderByRaw('min(id)')
            ->get();

        $chips = [];
        foreach ($rows as $row) {
            $chips[(int) $row->reactable_id][] = [
                'emoji' => (string) $row->emoji,
                'count' => (int) $row->total,
                'mine' => (bool) $row->mine,
            ];
        }

        return $chips;
    }

    /** @return list<array{emoji: string, count: int, mine: bool}> */
    public function of(Member $viewer, GroupMessage $message): array
    {
        return $this($viewer, new Collection([$message]))[(int) $message->getKey()] ?? [];
    }
}
