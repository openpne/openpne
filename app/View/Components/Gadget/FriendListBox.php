<?php

namespace App\View\Components\Gadget;

use App\Features\Friend\Queries\ListFriends;
use App\Models\Member;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * `subject` is the viewer on the home and the viewed member on a profile. The list is taken as the
 * subject's own view because a profile the viewer may not see is already a 404 in the controller.
 */
class FriendListBox extends Component
{
    /** @var list<array{url: string, imageUrl: ?string, name: string}> */
    public array $items;

    public int $rows;

    public int $cols;

    public string $type;

    /** The subject's whole friend count, which the grid slice does not report. */
    public int $total = 0;

    public bool $isSelf = false;

    /** @param array<string, mixed> $config */
    public function __construct(
        ListFriends $listFriends,
        public ?Member $subject = null,
        public array $config = [],
        public ?string $partId = null,
    ) {
        $this->type = (string) ($config['type'] ?? 'full');
        $this->rows = max(1, (int) ($config['row'] ?? 3));
        $this->cols = max(1, (int) ($config['col'] ?? 3));

        $friends = $subject !== null
            ? $listFriends->take($subject, $subject, $this->rows * $this->cols)
            : collect();

        $this->items = $friends->map(fn (Member $member) => [
            'url' => route('member.profile.show', $member),
            'imageUrl' => $member->avatar?->file?->thumbnailUrl(76, 76, square: true),
            'name' => $member->name,
        ])->all();

        if ($subject !== null && $this->items !== []) {
            $this->total = $listFriends->count($subject, $subject);
            $this->isSelf = $subject->is(auth()->user());
        }
    }

    public function render(): View
    {
        return view('components.gadget.friend-list-box');
    }
}
