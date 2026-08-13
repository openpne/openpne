<?php

namespace App\View\Components\Gadget;

use App\Features\GroupEvent\Queries\RecentJoinedGroupEvents;
use App\Models\Member;
use Illuminate\Contracts\View\View;

/** Recent events across the viewer's joined groups (home; subject = viewer). */
class RecentGroupEventComment extends GroupRecentListBox
{
    /** @param array<string, mixed> $config */
    public function __construct(
        RecentJoinedGroupEvents $events,
        public ?Member $subject = null,
        public array $config = [],
        public ?string $partId = null,
    ) {
        if ($subject !== null) {
            $this->entries = self::toEntries($events($subject, self::limit($config)), 'group.events.show');
        }
    }

    public function render(): View
    {
        return view('components.gadget.recent-group-event-comment');
    }
}
