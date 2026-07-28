<?php

namespace App\View\Components\Gadget;

use App\Features\Community\Queries\ListMemberCommunities;
use App\Models\Member;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The subject member's joined communities as a row × col thumbnail grid.
 *
 * Two queries at most: the grid slice and one aggregate for the "show all (n)" total.
 */
class CommunityJoinListBox extends Component
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
        ListMemberCommunities $listCommunities,
        public ?Member $subject = null,
        public array $config = [],
        public ?string $partId = null,
    ) {
        $this->type = (string) ($config['type'] ?? 'full');
        $this->rows = max(1, (int) ($config['row'] ?? 3));
        $this->cols = max(1, (int) ($config['col'] ?? 3));

        $communities = $subject !== null
            ? $listCommunities->take($subject, $this->rows * $this->cols)
            : collect();

        $this->items = $communities->map(fn ($community) => [
            'url' => route('community.show', $community),
            'imageUrl' => $community->image?->thumbnailUrl(76, 76, square: true),
            'name' => $community->name,
            'crown' => (bool) $community->owner_is_admin,
        ])->all();

        if ($subject !== null && $this->items !== []) {
            $this->total = $listCommunities->count($subject);
        }
    }

    public function render(): View
    {
        return view('components.gadget.community-join-list-box');
    }
}
