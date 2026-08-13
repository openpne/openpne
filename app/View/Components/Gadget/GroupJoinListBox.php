<?php

namespace App\View\Components\Gadget;

use App\Features\Group\Queries\ListMemberGroups;
use App\Models\Member;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The subject member's joined groups as a row × col thumbnail grid.
 *
 * The "show all (n)" total adds exactly one aggregate to the slice path (the slice itself carries its eager loads).
 */
class GroupJoinListBox extends Component
{
    /** @var list<array{url: string, imageUrl: ?string, name: string, crown: bool}> */
    public array $items;

    public int $rows;

    public int $cols;

    public string $type;

    /** The subject's whole joined-community count, which the grid slice does not report. */
    public int $total = 0;

    /** @param array<string, mixed> $config */
    public function __construct(
        ListMemberGroups $listCommunitys,
        public ?Member $subject = null,
        public array $config = [],
        public ?string $partId = null,
    ) {
        $this->type = (string) ($config['type'] ?? 'full');
        $this->rows = max(1, (int) ($config['row'] ?? 3));
        $this->cols = max(1, (int) ($config['col'] ?? 3));

        $groups = $subject !== null
            ? $listCommunitys->take($subject, $this->rows * $this->cols)
            : collect();

        $this->items = $groups->map(fn ($group) => [
            'url' => route('group.show', $group),
            'imageUrl' => $group->image?->thumbnailUrl(76, 76, square: true),
            'name' => $group->name,
            'crown' => (bool) $group->owner_is_admin,
        ])->all();

        if ($subject !== null && $this->items !== []) {
            $this->total = $listCommunitys->count($subject);
        }
    }

    public function render(): View
    {
        return view('components.gadget.group-join-list-box');
    }
}
