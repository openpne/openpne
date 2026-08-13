<?php

namespace App\View\Components\Gadget;

use App\Features\GroupTopic\Queries\RecentJoinedGroupTopics;
use App\Models\Member;
use Illuminate\Contracts\View\View;

/** Recent topics across the viewer's joined groups (home; subject = viewer). */
class RecentGroupTopicComment extends GroupRecentListBox
{
    /** @param array<string, mixed> $config */
    public function __construct(
        RecentJoinedGroupTopics $topics,
        public ?Member $subject = null,
        public array $config = [],
        public ?string $partId = null,
    ) {
        if ($subject !== null) {
            $this->entries = self::toEntries($topics($subject, self::limit($config)), 'group.topics.show');
        }
    }

    public function render(): View
    {
        return view('components.gadget.recent-group-topic-comment');
    }
}
