<?php

namespace App\Features\Profile\Queries;

use App\Features\Block\BlockLookup;
use App\Models\Member;
use App\Services\PresetProfileService;
use App\Support\PreferenceKey;
use App\Support\Visibility;

/**
 * The owner's age in whole years if the viewer may see it, else null.
 *
 * OpenPNE 3 derives age from the op_preset_birthday field but gates it with a *separate*
 * age_public_flag (App\Support\PreferenceKey::AgeVisibility), independent of that field's own
 * visibility. The birth year is therefore exposed only here — the birthday field itself renders
 * month/day only (MemberProfile::displayValue).
 *
 * Guests are fail-closed: OpenPNE 3 gates web-public age behind is_allow_web_public_flag_age
 * (default off) and OpenPNE 4 has no equivalent setting yet, so age is never shown to a guest.
 * The owner always sees their own age (self → Private clearance), an intentional divergence from
 * OpenPNE 3's getAge(true), matching how every other OpenPNE 4 field treats self.
 */
class VisibleAge
{
    public function __construct(private PresetProfileService $presets) {}

    public function __invoke(?Member $viewer, Member $owner): ?int
    {
        if ($viewer === null) {
            return null;
        }

        // A blocked viewer must not see the owner's age, mirroring ShowProfile's query-layer block
        // check (defense in depth; the profile page also 404s at the controller).
        if (! $viewer->is($owner) && BlockLookup::ownerBlocksViewer($owner, $viewer)) {
            return null;
        }

        $clearance = Visibility::clearanceFor($viewer, $owner);
        if ($owner->preference(PreferenceKey::AgeVisibility)->value > $clearance->value) {
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
