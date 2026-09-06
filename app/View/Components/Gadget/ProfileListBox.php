<?php

namespace App\View\Components\Gadget;

use App\Features\Profile\AgeVisibility;
use App\Features\Profile\Queries\ShowProfile;
use App\Features\Profile\Queries\VisibleAge;
use App\Models\Member;
use App\Support\PreferenceKey;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** The page-level owner→viewer block is the controller's; this resolves only per-field visibility. */
class ProfileListBox extends Component
{
    /** @var list<array{caption: string, value: string, linkify: bool, suffix: string|null}> */
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

        // OpenPNE 3 order: nickname, then Age (gated separately from the birthday field), then the
        // visible fields; only the free-text values are auto-linked (op_auto_link_text), not the
        // nickname or Age.
        $isOwner = $viewer?->is($subject) ?? false;
        $rows = [['caption' => __('%Nickname%'), 'value' => $subject->name, 'linkify' => false, 'suffix' => null]];
        if (($age = $visibleAge($viewer, $subject)) !== null) {
            $rows[] = [
                'caption' => __('Age'),
                'value' => __(':age years old', ['age' => $age]),
                'linkify' => false,
                'suffix' => $isOwner ? $subject->preference(PreferenceKey::AgeVisibility)->ownerCaption(AgeVisibility::allowsWebPublic()) : null,
            ];
        }
        foreach ($showProfile($viewer, $subject, $this->lang) ?? collect() as $field) {
            $rows[] = [
                'caption' => $field->profile->getCaption($this->lang),
                'value' => $field->display($this->lang),
                'linkify' => true,
                'suffix' => $isOwner ? $field->ownerCaption() : null,
            ];
        }

        $this->rows = $rows;
    }

    public function render(): View
    {
        return view('components.gadget.profile-list-box');
    }
}
