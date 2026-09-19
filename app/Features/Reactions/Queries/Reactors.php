<?php

namespace App\Features\Reactions\Queries;

use App\Features\Member\Serializers\MemberRefSerializer;
use App\Models\Concerns\HasReactions;
use App\Models\Member;
use App\Models\Reaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Reactors
{
    /** Past this the dialog has the exact count and no more names. */
    public const PER_EMOJI = 100;

    /**
     * @param  Model&HasReactions  $reactable
     * @return list<array{emoji: string, count: int, members: list<array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}>}>
     */
    public function __invoke(Model $reactable): array
    {
        $counts = DB::table('reactions')
            ->where('reactable_type', $reactable->getMorphClass())
            ->where('reactable_id', $reactable->getKey())
            ->select('emoji')
            ->selectRaw('count(*) as total')
            ->groupBy('emoji')
            // The chips' own order, so the dialog reads in the order the row it was opened from does.
            ->orderByRaw('min(created_at)')
            ->orderByRaw('min(id)')
            ->get();

        return $counts->map(fn (object $row): array => [
            'emoji' => (string) $row->emoji,
            'count' => (int) $row->total,
            'members' => $this->members($reactable, (string) $row->emoji),
        ])->values()->all();
    }

    /**
     * A row whose member is null is dropped rather than rendered: the withdrawal that cascades the
     * reaction away can commit between the count and this read.
     *
     * @param  Model&HasReactions  $reactable
     * @return list<array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}>
     */
    private function members(Model $reactable, string $emoji): array
    {
        return $reactable->reactions()
            ->where('emoji', $emoji)
            ->with('member.avatar.file')
            ->limit(self::PER_EMOJI)
            ->get()
            ->map(fn (Reaction $reaction): ?Member => $reaction->member)
            ->filter()
            ->map(fn (Member $member): array => MemberRefSerializer::ref($member))
            ->values()
            ->all();
    }
}
