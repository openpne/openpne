<?php

namespace App\Features\GroupTalk;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The "read up to here" position on a membership row: the `(talk_read_at, talk_read_message_id)`
 * tuple of the last message the member has seen.
 *
 * It lives on `group_members` rather than in a table of its own so that **membership implies
 * cursor** by the row's existence — a non-member reader cannot accumulate unread state at all, and
 * leaving takes the cursor with the membership (rejoining starts fresh, which is why an absence
 * counts as read). The message id is a **copied value, not a foreign key**: deleting the message a
 * cursor points at is a no-op, and the count simply falls as the row stops existing.
 *
 * Two operations, and both are total orders on the tuple, never on the timestamp alone — a MySQL
 * timestamp is second-precise, so `created_at` by itself cannot separate two messages in one second.
 */
final class TalkReadCursor
{
    /**
     * The cursor a membership created *now* should start from: the group's newest live message.
     * Everything already said is read; only what arrives afterwards is new (LINE's rule, and the one
     * that keeps joining a busy group from opening with hundreds of unread).
     *
     * The columns carry DB defaults (`useCurrent()` and 0) for the paths this helper cannot reach —
     * the history transfer's bulk insert, a hand-written row — but those are a **backstop, not the
     * initialization**. `now()` with id 0 is not the same boundary: a message written in the same
     * second as the join has a tuple of `(t, id>0)`, which compares greater than `(t, 0)` and would
     * show up unread. Reading the real latest tuple is what closes that second.
     *
     * @return array{talk_read_at: CarbonImmutable, talk_read_message_id: int}
     */
    public static function snapshot(int $groupId): array
    {
        $latest = DB::table('group_messages')
            ->where('group_id', $groupId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['id', 'created_at']);

        if ($latest === null) {
            return ['talk_read_at' => CarbonImmutable::now(), 'talk_read_message_id' => 0];
        }

        return [
            'talk_read_at' => CarbonImmutable::parse($latest->created_at),
            'talk_read_message_id' => (int) $latest->id,
        ];
    }

    /**
     * Move a membership's cursor forward to $at/$messageId, and only forward.
     *
     * The monotonic guard is in the WHERE clause rather than in a read-then-write, so it holds under
     * concurrency and makes the call idempotent: replaying an older position — a retried mark-read,
     * a second tab a page behind — changes nothing instead of re-marking read messages unread.
     *
     * @return bool whether the cursor actually moved
     */
    public static function advance(int $groupId, int $memberId, CarbonImmutable $at, int $messageId): bool
    {
        return self::membership($groupId, $memberId)
            ->where(fn (Builder $behind) => $behind
                ->where('talk_read_at', '<', $at)
                ->orWhere(fn (Builder $tie) => $tie
                    ->where('talk_read_at', '=', $at)
                    ->where('talk_read_message_id', '<', $messageId)))
            ->update(['talk_read_at' => $at, 'talk_read_message_id' => $messageId]) > 0;
    }

    /** Whether the member holds a membership in this group — the row the cursor lives on. */
    public static function exists(int $groupId, int $memberId): bool
    {
        return self::membership($groupId, $memberId)->exists();
    }

    /** @return Builder the one membership row, or none */
    private static function membership(int $groupId, int $memberId): Builder
    {
        return DB::table('group_members')->where('group_id', $groupId)->where('member_id', $memberId);
    }
}
