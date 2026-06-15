<?php

namespace App\View\Components\Gadget;

use App\Features\Profile\Data\ProfileFieldValue;
use App\Features\Profile\Queries\ShowProfile;
use App\Models\Member;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

/**
 * OpenPNE 3 member/profileListBox: the subject member's profile values, filtered to what the
 * current viewer may see. The page-level owner→viewer block is the controller's; here we only
 * resolve per-field visibility, so the viewer comes from the request.
 */
class ProfileListBox extends Component
{
    /** @var Collection<int, ProfileFieldValue> */
    public Collection $fields;

    public string $lang;

    /** @param array<string, mixed> $config */
    public function __construct(
        ShowProfile $showProfile,
        public ?Member $subject = null,
        public array $config = [],
        public ?string $partId = null,
    ) {
        $this->lang = app()->getLocale() === 'ja' ? 'ja_JP' : 'en';

        /** @var Member|null $viewer */
        $viewer = auth()->user();

        $this->fields = $subject !== null
            ? ($showProfile($viewer, $subject, $this->lang) ?? collect())
            : collect();
    }

    public function render(): View
    {
        return view('components.gadget.profile-list-box');
    }
}
