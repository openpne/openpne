<?php

namespace App\View\Components\Gadget;

use App\Features\Timeline\Queries\FriendFeed;
use App\Models\Member;
use Illuminate\Contracts\View\View;

/** The viewer and their friends' recent timeline (home; subject = viewer). */
class TimelineFriend extends TimelineBox
{
    /** @param array<string, mixed> $config */
    public function __construct(
        FriendFeed $feed,
        public ?Member $subject = null,
        public array $config = [],
        public ?string $partId = null,
    ) {
        $this->posts = $subject !== null ? $feed->take($subject, self::limit($config)) : collect();
    }

    public function render(): View
    {
        return view('components.gadget.timeline-friend');
    }
}
