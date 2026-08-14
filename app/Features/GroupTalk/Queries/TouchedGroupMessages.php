<?php

namespace App\Features\GroupTalk\Queries;

use App\Models\Group;
use App\Models\GroupMessage;
use Illuminate\Support\Collection;

/**
 * The messages whose reactions have changed since a version the client holds — the half of the poll
 * that {@see GroupTalkMessages} cannot answer, since a reaction moves nothing about a row's
 * (created_at, id) and the poll only ever reads forward from one.
 *
 * Keyset on the version, which is unique and monotonic within the group
 * (App\Features\GroupTalk\TalkReactionVersion), so a strict `>` neither repeats nor skips. Capped
 * like every other page: a tab left open behind a busy group catches up one page per poll.
 */
class TouchedGroupMessages
{
    /**
     * One page of touched rows, oldest version first, with one extra so the caller can tell a full
     * page from an exhausted one.
     *
     * @return Collection<int, GroupMessage>
     */
    public function __invoke(Group $group, int $after): Collection
    {
        return GroupMessage::query()
            ->where('group_id', $group->getKey())
            ->where('reactions_version', '>', $after)
            // The same fan-out a page of the conversation takes: the client replaces whole rows with
            // these, so they have to serialize to the same shape.
            ->with(GroupTalkMessages::WITH)
            ->orderBy('reactions_version')
            ->limit(GroupTalkMessages::PER_PAGE + 1)
            ->get();
    }
}
