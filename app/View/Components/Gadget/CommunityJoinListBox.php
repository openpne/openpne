<?php

namespace App\View\Components\Gadget;

use App\Features\Community\Queries\ListMemberCommunities;
use App\Models\Community;
use App\Models\Member;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

/**
 * OpenPNE 3 community/joinListBox: the subject member's joined communities as a row × col
 * thumbnail grid.
 */
class CommunityJoinListBox extends Component
{
    /** @var Collection<int, Community> */
    public Collection $communities;

    public string $type;

    /** @param array<string, mixed> $config */
    public function __construct(
        ListMemberCommunities $listCommunities,
        public ?Member $subject = null,
        public array $config = [],
        public ?string $partId = null,
    ) {
        $this->type = (string) ($config['type'] ?? 'full');
        $limit = max(1, (int) ($config['row'] ?? 3) * (int) ($config['col'] ?? 3));
        $this->communities = $subject !== null
            ? collect($listCommunities($subject, $limit)->items())
            : collect();
    }

    public function render(): View
    {
        return view('components.gadget.community-join-list-box');
    }
}
