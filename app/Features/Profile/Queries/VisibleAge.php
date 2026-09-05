<?php

namespace App\Features\Profile\Queries;

use App\Features\Block\BlockLookup;
use App\Features\Profile\AgeVisibility;
use App\Models\Member;
use App\Services\PresetProfileService;
use App\Support\PreferenceKey;
use App\Support\Visibility;

/** See docs/internals/member-profile.md, "Age (derived from the birthday)". */
class VisibleAge
{
    public function __construct(private PresetProfileService $presets) {}

    public function __invoke(?Member $viewer, Member $owner): ?int
    {
        $ageVisibility = $owner->preference(PreferenceKey::AgeVisibility);

        if ($ageVisibility === Visibility::Open && ! AgeVisibility::allowsWebPublic()) {
            return null;
        }

        if ($viewer === null) {
            $clearance = Visibility::Open;
        } else {
            if (! $viewer->is($owner) && BlockLookup::ownerBlocksViewer($owner, $viewer)) {
                return null;
            }

            $clearance = Visibility::clearanceFor($viewer, $owner);
        }

        if ($ageVisibility->value > $clearance->value) {
            return null;
        }

        $birthdayName = $this->presets->nameForKey('birthday')['name'];
        $birth = $owner->memberProfiles()
            ->whereHas('profile', fn ($query) => $query->where('name', $birthdayName))
            ->first()?->value_datetime;

        if ($birth === null) {
            return null;
        }

        $now = now();
        $age = $now->year - $birth->year - ((int) $now->format('md') < (int) $birth->format('md') ? 1 : 0);

        return $age >= 0 ? $age : null;
    }
}
