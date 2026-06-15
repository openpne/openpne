<?php

namespace App\View\Components\Gadget;

use App\Features\Friend\Queries\ListFriends;
use App\Models\Member;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

/**
 * OpenPNE 3 friend/friendListBox: the subject member's friends as a row × col thumbnail grid. The
 * subject is also the viewer (home/profile own pages), so block-aware visibility is satisfied.
 */
class FriendListBox extends Component
{
    /** @var Collection<int, Member> */
    public Collection $friends;

    public string $type;

    /** @param array<string, mixed> $config */
    public function __construct(
        ListFriends $listFriends,
        public ?Member $subject = null,
        public array $config = [],
        public ?string $partId = null,
    ) {
        $this->type = (string) ($config['type'] ?? 'full');
        $limit = max(1, (int) ($config['row'] ?? 3) * (int) ($config['col'] ?? 3));
        $this->friends = $subject !== null
            ? collect($listFriends($subject, $subject, $limit)->items())
            : collect();
    }

    public function render(): View
    {
        return view('components.gadget.friend-list-box');
    }
}
