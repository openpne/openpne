<?php

namespace App\View\Components\Gadget;

use App\Features\CommunityEvent\Queries\RecentJoinedCommunityEvents;
use App\Models\Member;
use Illuminate\Contracts\View\View;

/** Recent events across the viewer's joined communities (home; subject = viewer). */
class RecentCommunityEventComment extends CommunityRecentListBox
{
    /** @param array<string, mixed> $config */
    public function __construct(
        RecentJoinedCommunityEvents $events,
        public ?Member $subject = null,
        public array $config = [],
        public ?string $partId = null,
    ) {
        if ($subject !== null) {
            $this->entries = self::toEntries($events($subject, self::limit($config)), 'communityEvent.show');
        }
    }

    public function render(): View
    {
        return view('components.gadget.recent-community-event-comment');
    }
}
