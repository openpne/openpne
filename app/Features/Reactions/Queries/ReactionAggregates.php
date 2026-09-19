<?php

namespace App\Features\Reactions\Queries;

use App\Models\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Counted in SQL rather than off a loaded relation: the rows behind a chip row are one per reactor
 * per emoji. Groups are ordered by their earliest row rather than by count, so the chips read in the
 * order the emoji first appeared on the content.
 */
class ReactionAggregates
{
    /**
     * @param  class-string<Model>  $reactable  the content's model; its morph alias is read from it,
     *                                          never written as a literal
     * @param  list<int>  $ids
     * @return array<int, list<array{emoji: string, count: int, mine: bool}>> keyed by content id;
     *                                                                        content nobody reacted to has no key at all
     */
    public function __invoke(Member $viewer, string $reactable, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = DB::table('reactions')
            ->where('reactable_type', (new $reactable)->getMorphClass())
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
    public function of(Member $viewer, Model $reactable): array
    {
        return $this($viewer, $reactable::class, [(int) $reactable->getKey()])[(int) $reactable->getKey()] ?? [];
    }
}
