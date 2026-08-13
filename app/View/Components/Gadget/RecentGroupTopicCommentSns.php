<?php

namespace App\View\Components\Gadget;

use App\Features\GroupTopic\Queries\RecentPublicGroupTopics;
use App\Models\Member;
use Illuminate\Contracts\View\View;

/** Recent topics across every public group (home; viewer-independent, members only). */
class RecentGroupTopicCommentSns extends GroupRecentListBox
{
    /** @param array<string, mixed> $config */
    public function __construct(
        RecentPublicGroupTopics $topics,
        public ?Member $subject = null,
        public array $config = [],
        public ?string $partId = null,
    ) {
        // Viewer-independent (OpenPNE 3): the query takes no viewer, so every member sees the same feed.
        if ($subject !== null) {
            $this->entries = self::toEntries($topics(self::limit($config)), 'group.topics.show');
        }
    }

    public function render(): View
    {
        return view('components.gadget.recent-group-topic-comment-sns');
    }
}
