<?php

namespace App\Features\GroupTalk\Queries;

use App\Features\Member\Serializers\MemberRefSerializer;
use App\Models\GroupMessage;
use App\Models\Member;
use App\Models\Reaction;
use Illuminate\Support\Facades\DB;

/**
 * Who reacted to one message, grouped by emoji — what a chip's dialog opens on, and the only place
 * names travel at all.
 *
 * Bounded on purpose: the count is exact, the names stop at {@see PER_EMOJI}. This is the one part
 * of a reaction whose size grows with the room, a dialog is read by a person, and an unbounded read
 * would ship a thousand rows and their avatars to draw the first screenful.
 *
 * Two reads rather than one, because the two halves are bounded differently: the counts come from a
 * grouped scan over every row, the names from a capped read per emoji — of which there are at most
 * as many as the site has ever offered.
 */
class MessageReactors
{
    /** Names per emoji. Past this the dialog has the count and nothing more. */
    public const PER_EMOJI = 100;

    /** @return list<array{emoji: string, count: int, members: list<array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}>}> */
    public function __invoke(GroupMessage $message): array
    {
        $counts = DB::table('reactions')
            ->where('reactable_type', $message->getMorphClass())
            ->where('reactable_id', $message->getKey())
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
            'members' => $this->members($message, (string) $row->emoji),
        ])->values()->all();
    }

    /**
     * The first PER_EMOJI reactors, in the order they reacted (the relation's own). A row whose
     * member is null is dropped rather than rendered: the withdrawal that cascades the reaction away
     * can commit between the count and this read.
     *
     * @return list<array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}>
     */
    private function members(GroupMessage $message, string $emoji): array
    {
        return $message->reactions()
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
