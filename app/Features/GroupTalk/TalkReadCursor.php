<?php

namespace App\Features\GroupTalk;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The cursor lives on `group_members`, so membership implies it and the message id is a copied value
 * rather than a foreign key (docs/internals/group-talk.md, "Unread"). Every comparison is the whole
 * tuple: a MySQL timestamp is second-precise, so `created_at` alone cannot separate two messages
 * written in one second.
 */
final class TalkReadCursor
{
    /**
     * The columns' DB defaults are a backstop for the paths this helper cannot reach, not the
     * initialization: a message written in the same second as the join has the tuple `(t, id>0)`,
     * which compares greater than the default `(t, 0)` and would show up unread.
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
     * Forward only, with the guard in the `WHERE` clause rather than in a read-then-write, so
     * replaying an older position under concurrency changes nothing.
     *
     * @return bool whether the cursor actually moved
     */
    public static function advance(int $groupId, int $memberId, CarbonImmutable $at, int $messageId): bool
    {
        return self::whereBehind(self::membership($groupId, $memberId), $at, $messageId)
            ->update(['talk_read_at' => $at, 'talk_read_message_id' => $messageId]) > 0;
    }

    /** A member with no membership row holds no cursor, and so is not behind anything. */
    public static function isBehind(int $groupId, int $memberId, CarbonImmutable $at, int $messageId): bool
    {
        return self::whereBehind(self::membership($groupId, $memberId), $at, $messageId)->exists();
    }

    /**
     * Written out rather than as SQL's row constructor, which SQLite does not support.
     *
     * @param  Builder  $membership  the membership query to narrow
     */
    private static function whereBehind(Builder $membership, CarbonImmutable $at, int $messageId): Builder
    {
        return $membership->where(fn (Builder $behind) => $behind
            ->where('talk_read_at', '<', $at)
            ->orWhere(fn (Builder $tie) => $tie
                ->where('talk_read_at', '=', $at)
                ->where('talk_read_message_id', '<', $messageId)));
    }

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
