<?php

namespace App\View\Components\Gadget;

use App\Features\Timeline\Queries\AllMemberFeed;
use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

/** OpenPNE 3 allMemberActivityBox: the whole SNS's members-only activity (home; subject = viewer). */
class AllMemberActivityBox extends Component
{
    /** @var Collection<int, TimelinePost> */
    public Collection $posts;

    public bool $showForm;

    /** @param array<string, mixed> $config */
    public function __construct(
        AllMemberFeed $feed,
        public ?Member $subject = null,
        public array $config = [],
        public ?string $partId = null,
    ) {
        $row = max(1, (int) ($config['row'] ?? 5));
        $this->posts = $subject !== null ? $feed->take($subject, $row) : collect();
        $this->showForm = (int) ($config['is_viewable_activity_form'] ?? 1) === 1;
    }

    public function render(): View
    {
        return view('components.gadget.all-member-activity-box');
    }
}
