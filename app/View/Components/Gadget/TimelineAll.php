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
        public int $limit = 0,
    ) {
        $this->limit = self::limit($config);
        $this->posts = collect();
        if ($subject !== null) {
            $this->keep($feed->take($subject, $this->limit + 1), $this->limit);
        }
        $this->attachInlineReplies($recentReplies);
    }

    public function render(): View
    {
        return view('components.gadget.timeline-all');
    }
}
