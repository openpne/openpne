<?php

namespace App\View\Components\Gadget;

use App\Features\Profile\Queries\ShowProfile;
use App\Features\Profile\Queries\VisibleAge;
use App\Models\Member;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * OpenPNE 3 member/profileListBox: the subject member's profile values, filtered to what the
 * current viewer may see. OpenPNE 3 always seeds the nickname row first, so the box renders even
 * for a member with no visible fields; the visible profile fields follow. The page-level
 * owner→viewer block is the controller's; here we only resolve per-field visibility.
 */
class ProfileListBox extends Component
{
    /** @var list<array{caption: string, value: string}> */
    public array $rows;

    public string $lang;

    /** @param array<string, mixed> $config */
    public function __construct(
        ShowProfile $showProfile,
        VisibleAge $visibleAge,
        public ?Member $subject = null,
        public array $config = [],
        public ?string $partId = null,
    ) {
        $this->lang = app()->getLocale() === 'ja' ? 'ja_JP' : 'en';

        if ($subject === null) {
            $this->rows = [];

            return;
        }

        /** @var Member|null $viewer */
        $viewer = auth()->user();

        // OpenPNE 3 seeds the nickname row, then Age right after it (gated separately from the
        // birthday field), then the visible profile fields.
        $rows = [['caption' => __('%Nickname%'), 'value' => $subject->name]];
        if (($age = $visibleAge($viewer, $subject)) !== null) {
            $rows[] = ['caption' => __('Age'), 'value' => __(':age years old', ['age' => $age])];
        }
        foreach ($showProfile($viewer, $subject, $this->lang) ?? collect() as $field) {
            $rows[] = ['caption' => $field->profile->getCaption($this->lang), 'value' => $field->display($this->lang)];
        }

        $this->rows = $rows;
    }

    public function render(): View
    {
        return view('components.gadget.profile-list-box');
    }
}
