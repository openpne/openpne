<?php

namespace App\View\Components\Gadget;

use App\Features\CommunityTopic\Queries\RecentPublicCommunityTopics;
use App\Models\Member;
use Illuminate\Contracts\View\View;

/** Recent topics across every public community (home; viewer-independent, members only). */
class RecentCommunityTopicCommentSns extends CommunityRecentListBox
{
    /** @param array<string, mixed> $config */
    public function __construct(
        RecentPublicCommunityTopics $topics,
        public ?Member $subject = null,
        public array $config = [],
        public ?string $partId = null,
    ) {
        // Viewer-independent (OpenPNE 3): the query takes no viewer, so every member sees the same feed.
        if ($subject !== null) {
            $this->entries = self::toEntries($topics(self::limit($config)), 'communityTopic.show');
        }
    }

    public function render(): View
    {
        return view('components.gadget.recent-community-topic-comment-sns');
    }
}
