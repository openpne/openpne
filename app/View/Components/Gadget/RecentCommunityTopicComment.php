<?php

namespace App\View\Components\Gadget;

use App\Features\CommunityTopic\Queries\RecentJoinedCommunityTopics;
use App\Models\Member;
use Illuminate\Contracts\View\View;

/** Recent topics across the viewer's joined groups (home; subject = viewer). */
class RecentCommunityTopicComment extends CommunityRecentListBox
{
    /** @param array<string, mixed> $config */
    public function __construct(
        RecentJoinedCommunityTopics $topics,
        public ?Member $subject = null,
        public array $config = [],
        public ?string $partId = null,
    ) {
        if ($subject !== null) {
            $this->entries = self::toEntries($topics($subject, self::limit($config)), 'communityTopic.show');
        }
    }

    public function render(): View
    {
        return view('components.gadget.recent-community-topic-comment');
    }
}
