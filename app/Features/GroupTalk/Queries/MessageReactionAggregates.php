<?php

namespace App\Features\GroupTalk\Queries;

use App\Models\GroupMessage;
use App\Models\Member;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The chip row of every message on a page — each emoji, how many hold it, and whether the viewer is
 * one of them — in one grouped read.
 *
 * Counted in SQL rather than off a loaded relation. A chip row is a handful of numbers, but the rows
 * behind it are one per reactor per emoji, so hydrating a page's worth costs the room's size on
 * every page and every poll for something the payload never names. Who reacted is a separate
 * question, asked only when a dialog opens ({@see MessageReactors}).
 *
 * The order is the one the chips are drawn in — the emoji that appeared first, first — which is why
 * a group is sorted by its earliest row rather than by its count.
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
            // The viewer's own answer off the same scan; a second query would read the same rows.
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

    /**
     * One message's chip row — its whole authoritative state, which is why the add and remove
     * endpoints answer with this rather than a delta the client would have to apply.
     *
     * @return list<array{emoji: string, count: int, mine: bool}>
     */
    public function of(Member $viewer, GroupMessage $message): array
    {
        return $this($viewer, new Collection([$message]))[(int) $message->getKey()] ?? [];
    }
}
