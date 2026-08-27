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
 * Groups that got talking in the window.
 *
 * The item is the stretch, not any message in it, so nothing about a message is stored: no anchor,
 * no id. The page re-resolves the burst live through {@see TalkSampleDigest}
 * over the same window, which is what lets a deleted message simply not be there rather than leave a
 * hole. It is also why this section may feature a group again next week — the news is what was said
 * since, and that is a different stretch every time.
 */
final class TalkBurstCandidates
{
    /** Below this a room is not talking, it is exchanging a message. */
    public const MIN_MESSAGES = 3;

    public function alias(): string
    {
        return (new Group)->getMorphClass();
    }

    /** @return Collection<int, PlannedItem> */
    public function __invoke(HomeIssueWindow $window, int $limit, int $minMessages = self::MIN_MESSAGES): Collection
    {
        // Correlated rather than a second pass over the winning groups, because the reaction count
        // is part of the score: a second pass would have to rank before it knew the numbers it ranks
        // by. Nothing bounds the window but the range itself — no index leads with created_at — so
        // this reads a day's messages across every group, which is what once a day affords.
        $reactions = DB::table('reactions')
            ->selectRaw('count(*)')
            ->join('group_messages as reacted_messages', 'reacted_messages.id', '=', 'reactions.reactable_id')
            ->where('reactions.reactable_type', (new GroupMessage)->getMorphClass())
            ->whereColumn('reacted_messages.group_id', 'groups.id');
        $window->apply($reactions, 'reacted_messages.created_at');

        $bursts = DB::table('group_messages')
            ->join('groups', 'groups.id', '=', 'group_messages.group_id')
            ->where('groups.topic_read_access', TopicReadAccess::Everyone->value)
            ->select('groups.id')
            ->selectRaw('count(*) as message_count')
            // COUNT(DISTINCT) skips the null a withdrawn author leaves, which is the answer wanted:
            // nobody is not somebody who spoke.
            ->selectRaw('count(distinct group_messages.member_id) as author_count')
            ->selectRaw('max(group_messages.created_at) as last_said')
            ->selectSub($reactions, 'reaction_count')
            ->groupBy('groups.id')
            ->havingRaw('count(*) >= ?', [$minMessages])
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
