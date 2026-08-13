<?php

namespace App\View\Components\Gadget;

use App\Features\GroupEvent\Queries\RecentPublicGroupEvents;
use App\Models\Member;
use Illuminate\Contracts\View\View;

/** Recent events across every public community (home; viewer-independent, members only). */
class RecentGroupEventCommentSns extends GroupRecentListBox
{
    /** @param array<string, mixed> $config */
    public function __construct(
        RecentPublicGroupEvents $events,
        public ?Member $subject = null,
        public array $config = [],
        public ?string $partId = null,
    ) {
        // Viewer-independent (OpenPNE 3): the query takes no viewer, so every member sees the same feed.
        if ($subject !== null) {
            $this->entries = self::toEntries($events(self::limit($config)), 'group.events.show');
        }
    }

    public function render(): View
    {
        return view('components.gadget.recent-group-event-comment-sns');
    }
}
