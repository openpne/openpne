<?php

namespace App\Features\GroupTalk\Queries;

use App\Models\Group;
use App\Models\GroupMessage;
use Illuminate\Support\Collection;

/**
 * Keyset on the version, which the group row's lock makes unique and monotonic within the group, so
 * a strict `>` neither repeats nor skips.
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
            ->with(GroupTalkMessages::WITH)
            ->orderBy('reactions_version')
            ->limit(GroupTalkMessages::PER_PAGE + 1)
            ->get();
    }
}
