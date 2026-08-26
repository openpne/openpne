<?php

namespace App\View\Components\Gadget;

use App\Features\Timeline\Queries\HomeFeed;
use App\Features\Timeline\Queries\RecentReplies;
use App\Models\Member;
use Illuminate\Contracts\View\View;

/** The whole SNS's recent timeline (home; subject = viewer). */
class TimelineAll extends TimelineBox
{
    /** @param array<string, mixed> $config */
    public function __construct(
        HomeFeed $feed,
        RecentReplies $recentReplies,
        public ?Member $subject = null,
        public array $config = [],
        public ?string $partId = null,
    ) {
        $this->posts = $subject !== null ? $feed->take($subject, self::limit($config)) : collect();
        $this->attachInlineReplies($recentReplies);
    }

    public function render(): View
    {
        return view('components.gadget.timeline-all');
    }
}
