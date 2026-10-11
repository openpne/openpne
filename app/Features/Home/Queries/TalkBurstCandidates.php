<?php

declare(strict_types=1);

namespace App\Features\Home\Queries;

use App\Features\GroupTalk\Queries\TalkSampleDigest;
use App\Features\GroupTopic\TopicReadAccess;
use App\Features\Home\Data\HomeIssueWindow;
use App\Features\Home\Data\PlannedItem;
use App\Models\Group;
use App\Models\GroupMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Groups talked in during the window: one message is enough, since the score ranks rooms rather
 * than admitting them. Nothing about a message is stored, which lets the page re-resolve the stretch
 * live through {@see TalkSampleDigest} and is why this section may feature a group again
 * (docs/internals/home-issues.md, "Frozen stats are provenance").
 */
final class TalkBurstCandidates
{
    public function alias(): string
    {
        return (new Group)->getMorphClass();
    }

    /** @return Collection<int, PlannedItem> */
    public function __invoke(HomeIssueWindow $window, int $limit): Collection
    {
        // One row per room rather than a subquery correlated per message: MySQL evaluates a correlated
        // projection before GROUP BY, once for every message in the window (docs/internals/ordering.md,
        // "One index per axis").
        $reactions = DB::table('reactions')
            ->join('group_messages as reacted_messages', 'reacted_messages.id', '=', 'reactions.reactable_id')
            ->where('reactions.reactable_type', (new GroupMessage)->getMorphClass())
            ->select('reacted_messages.group_id')
            // Named apart from the alias below: SQLite resolves a name in an ORDER BY expression against the joined columns before the select list.
            ->selectRaw('count(*) as reactions')
            ->groupBy('reacted_messages.group_id');
        $window->apply($reactions, 'reacted_messages.created_at');

        $bursts = DB::table('group_messages')
            ->join('groups', 'groups.id', '=', 'group_messages.group_id')
            ->leftJoinSub($reactions, 'reacted', 'reacted.group_id', '=', 'groups.id')
            ->where('groups.topic_read_access', TopicReadAccess::Everyone->value)
            ->select('groups.id')
            ->selectRaw('count(*) as message_count')
            // COUNT(DISTINCT) skips the null a withdrawn author leaves, which is the answer wanted:
            // nobody is not somebody who spoke.
            ->selectRaw('count(distinct group_messages.member_id) as author_count')
            ->selectRaw('max(group_messages.created_at) as last_said')
            // One joined row per room, so MAX() is that row's count; it keeps MySQL's only-full-group-by happy.
            ->selectRaw('coalesce(max(reacted.reactions), 0) as reaction_count')
            ->groupBy('groups.id')
            ->orderByRaw('message_count + author_count + reaction_count desc')
            ->orderByDesc('last_said')
            ->orderByDesc('groups.id')
            ->limit($limit);
        $window->apply($bursts, 'group_messages.created_at');

        return collect($bursts->get())->map(fn (object $row): PlannedItem => $this->item($row, $window));
    }

    private function item(object $row, HomeIssueWindow $window): PlannedItem
    {
        $messages = (int) $row->message_count;
        $authors = (int) $row->author_count;
        $reactions = (int) $row->reaction_count;

        return new PlannedItem(
            $this->alias(),
            (int) $row->id,
            $messages + $authors + $reactions,
            [
                'messages' => $messages,
                'authors' => $authors,
                'reactions' => $reactions,
                // The stretch the numbers describe, so provenance stays readable once the issue's
                // own window has scrolled out of reach.
                'since' => $window->start->toIso8601String(),
                'until' => $window->end->toIso8601String(),
            ],
            // The burst's own instant: when it last had something to say.
            CarbonImmutable::parse((string) $row->last_said),
        );
    }
}
