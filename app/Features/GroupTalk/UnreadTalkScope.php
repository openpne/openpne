<?php

namespace App\Features\GroupTalk;

use App\Models\GroupMessage;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/**
 * What counts as unread in a group's talk, in one place because several queries ask it — the
 * per-group counts on the group list, the nav's "how many groups have something new", and the
 * absence digest's sample — and they must never answer differently.
 *
 * A message is unread for a member when it is newer than their cursor and somebody else wrote it.
 * The visible set is the same one the talk page shows (docs/internals/group-talk.md): every live
 * row, including authors who have left the group or blocked the viewer, because talk applies no
 * per-row filter.
 */
final class UnreadTalkScope
{
    /**
     * Correlate a `group_messages` query to a membership row of $membershipTable.
     *
     * @param  Builder  $messages  a query already selecting from `group_messages`
     * @param  string  $membershipTable  the correlated `group_members` table/alias in the outer query
     * @param  int  $viewerId  the member whose cursor and authorship are being compared
     */
    public static function correlate(Builder $messages, string $membershipTable, int $viewerId): Builder
    {
        return $messages
            ->whereColumn('group_messages.group_id', "{$membershipTable}.group_id")
            // Strictly after the cursor tuple, written out rather than as a row constructor: SQLite
            // has none, and comparing created_at alone would call a message written in the same
            // second as the cursor unread for ever.
            ->where(fn (Builder $after) => $after
                ->whereColumn('group_messages.created_at', '>', "{$membershipTable}.talk_read_at")
                ->orWhere(fn (Builder $tie) => $tie
                    ->whereColumn('group_messages.created_at', '=', "{$membershipTable}.talk_read_at")
                    ->whereColumn('group_messages.id', '>', "{$membershipTable}.talk_read_message_id")))
            // Somebody else's. The IS NULL arm is load-bearing, not defensive: `member_id != ?` is
            // UNKNOWN for a withdrawn author's row, so it would silently drop exactly the messages
            // the talk page still shows under the "Withdrawn member" label.
            ->where(fn (Builder $others) => $others
                ->whereNull('group_messages.member_id')
                ->orWhere('group_messages.member_id', '!=', $viewerId));
    }

    /**
     * The same two predicates against a boundary already in hand, for a caller reading the unread
     * rows themselves rather than counting them through a membership row. Same class so the sample
     * and the count cannot drift apart: a digest built from a wider or narrower set than the number
     * printed on it would be describing a different backlog.
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
