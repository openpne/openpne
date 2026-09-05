<?php

namespace App\Features\Profile\Queries;

use App\Features\Block\BlockLookup;
use App\Models\Member;
use App\Models\MemberProfile;
use App\Services\PresetProfileService;
use App\Support\Visibility;
use Carbon\CarbonInterface;

/**
 * Gated by the birthday field's own visibility, never by the age gate
 * (docs/internals/member-profile.md, "Age (derived from the birthday)").
 */
class VisibleBirthday
{
    public function __construct(private PresetProfileService $presets) {}

    public function __invoke(?Member $viewer, Member $owner): ?CarbonInterface
    {
        if ($viewer !== null && ! $viewer->is($owner) && BlockLookup::ownerBlocksViewer($owner, $viewer)) {
            return null;
        }

        $isGuest = $viewer === null;
        $clearance = $isGuest ? Visibility::Open : Visibility::clearanceFor($viewer, $owner);

        $birthdayName = $this->presets->nameForKey('birthday')['name'];

        /** @var MemberProfile|null $row */
        $row = $owner->memberProfiles()
            ->whereHas('profile', fn ($query) => $query->where('name', $birthdayName))
            ->with('profile')
            ->first();

        if ($row === null || $row->value_datetime === null) {
            return null;
        }

        if ($this->effectiveVisibility($row)->value > $clearance->value) {
            return null;
        }

        // A guest additionally only sees a web-public field, mirroring ShowProfile.
        if ($isGuest && ! $row->profile->is_public_web) {
            return null;
        }

        return $row->value_datetime;
    }

    private function effectiveVisibility(MemberProfile $row): Visibility
    {
        $profile = $row->profile;

        if ($profile->is_edit_public_flag) {
            return $row->visibility ?? $profile->default_visibility;
        }

        return $profile->default_visibility;
    }
}
