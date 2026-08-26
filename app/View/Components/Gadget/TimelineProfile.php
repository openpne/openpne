<?php

namespace App\View\Components\Gadget;

use App\Features\Timeline\Queries\MemberTimeline;
use App\Features\Timeline\Queries\RecentReplies;
use App\Features\Timeline\Queries\RowsPage;
use App\Models\Member;
use Illuminate\Contracts\View\View;

/** A profile owner's recent timeline (profile; subject = owner, viewer = the current member). */
class TimelineProfile extends TimelineBox
{
    /**
     * OpenPNE 3 hard-coded 20 posts on the JS side; this kind has no config. The load-more names no
     * per_page, so the fragment's default has to be the same twenty.
     */
    private const LIMIT = RowsPage::DEFAULT;

    /** @param array<string, mixed> $config */
    public function __construct(
        MemberTimeline $query,
        RecentReplies $recentReplies,
        public ?Member $subject = null,
        public array $config = [],
        public ?string $partId = null,
    ) {
        /** @var Member|null $viewer */
        $viewer = auth()->user();
        $this->posts = collect();
        if ($viewer !== null && $subject !== null) {
            $this->keep($query->take($viewer, $subject, self::LIMIT + 1), self::LIMIT);
        }
        $this->attachInlineReplies($recentReplies);
    }

    public function render(): View
    {
        return view('components.gadget.timeline-profile');
    }
}
