<?php

namespace App\View\Components\Gadget;

use App\Features\Community\Queries\ListMemberCommunities;
use App\Models\Member;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * OpenPNE 3 community/joinListBox: the subject member's joined communities as a row × col
 * thumbnail grid.
 */
class CommunityJoinListBox extends Component
{
    /** @var list<array{url: string, imageUrl: ?string, name: string}> */
    public array $items;

    public int $rows;

    public int $cols;

    public string $type;

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
        ])->all();
    }

    public function render(): View
    {
        return view('components.gadget.community-join-list-box');
    }
}
