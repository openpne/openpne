<?php

namespace App\View\Components\Gadget;

use App\Features\Timeline\Queries\FriendFeed;
use App\Features\Timeline\Queries\MemberTimeline;
use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

/**
 * OpenPNE 3 activityBox: the same kind renders by context. On the home it is the viewer + friends feed
 * (subject = viewer); on a profile it is the owner's timeline under the viewer's clearance (subject =
 * owner). The heading and "More" target follow the context. A guest never reaches this members-only
 * kind (GadgetService hides it), so a null viewer/subject defensively yields an empty box.
 */
class ActivityBox extends Component
{
    /** @var Collection<int, TimelinePost> */
    public Collection $posts;

    public string $title;

    public string $moreUrl;

    /** @param array<string, mixed> $config */
    public function __construct(
        FriendFeed $friendFeed,
        MemberTimeline $memberTimeline,
        public string $context = 'home',
        public ?Member $subject = null,
        public array $config = [],
        public ?string $partId = null,
    ) {
        $row = max(1, (int) ($config['row'] ?? 5));

        if ($context === 'profile') {
            /** @var Member|null $viewer */
            $viewer = auth()->user();
            $owner = $subject;
            $this->posts = ($viewer !== null && $owner !== null)
                ? $memberTimeline->take($viewer, $owner, $row)
                : collect();
            $this->title = ($owner !== null && $owner->is($viewer))
                ? __('My %activity%')
                : __(":name's %activity%", ['name' => $owner?->name ?? '']);
            $this->moreUrl = $owner !== null ? route('timeline.member', $owner) : route('timeline.index');
        } else {
            // Home: the subject is the viewer.
            $this->posts = $subject !== null ? $friendFeed->take($subject, $row) : collect();
            $this->title = __('%activity% of %my_friend%');
            $this->moreUrl = route('timeline.index');
        }
    }

    public function render(): View
    {
        return view('components.gadget.activity-box');
    }
}
