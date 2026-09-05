<?php

namespace App\Features\GroupTalk;

use App\Models\GroupMessage;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/**
 * The counted set is the one the page shows: no per-row filter, so authors who have left the group
 * or blocked the viewer still count (docs/internals/group-talk.md, "Two different numbers").
 */
final class UnreadTalkScope
{
    /**
     * @param  Builder  $messages  a query already selecting from `group_messages`
     * @param  string  $membershipTable  the correlated `group_members` table/alias in the outer query
     * @param  int  $viewerId  the member whose cursor and authorship are being compared
     */
    public static function correlate(Builder $messages, string $membershipTable, int $viewerId): Builder
    {
        return $messages
            ->whereColumn('group_messages.group_id', "{$membershipTable}.group_id")
            // Strictly after the cursor tuple, written out rather than as a row constructor: SQLite
            // has none, and `created_at` alone cannot separate two messages written in one second.
            ->where(fn (Builder $after) => $after
                ->whereColumn('group_messages.created_at', '>', "{$membershipTable}.talk_read_at")
                ->orWhere(fn (Builder $tie) => $tie
                    ->whereColumn('group_messages.created_at', '=', "{$membershipTable}.talk_read_at")
                    ->whereColumn('group_messages.id', '>', "{$membershipTable}.talk_read_message_id")))
            // The `IS NULL` arm is load-bearing: `member_id != ?` is UNKNOWN for a withdrawn
            // author's row, which would drop the messages the page still shows.
            ->where(fn (Builder $others) => $others
                ->whereNull('group_messages.member_id')
                ->orWhere('group_messages.member_id', '!=', $viewerId));
    }

    /**
     * The same predicates as {@see correlate()}, so a sample and the count printed on it cannot
     * describe different backlogs.
     *
     * @param  EloquentBuilder<GroupMessage>  $messages
     * @return EloquentBuilder<GroupMessage>
     */
    public static function since(EloquentBuilder $messages, GroupTalkCursor $boundary, int $viewerId): EloquentBuilder
    {
        return $messages
            ->where(fn (EloquentBuilder $after) => $after
                ->where('group_messages.created_at', '>', $boundary->at)
                ->orWhere(fn (EloquentBuilder $tie) => $tie
                    ->where('group_messages.created_at', '=', $boundary->at)
                    ->where('group_messages.id', '>', $boundary->id)))
            ->where(fn (EloquentBuilder $others) => $others
                ->whereNull('group_messages.member_id')
                ->orWhere('group_messages.member_id', '!=', $viewerId));
    }
}
