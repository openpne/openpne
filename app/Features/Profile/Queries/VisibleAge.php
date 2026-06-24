<?php

namespace App\Features\Profile\Queries;

use App\Features\Block\BlockLookup;
use App\Features\Profile\AgeVisibility;
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
 * Web-public (Open) age is shown only while the SNS allows web-public age (AgeVisibility::allowsWebPublic,
 * OpenPNE 3 is_allow_web_public_flag_age); disabled, an Open age is shown to nobody — matching
 * OpenPNE 3's getAge(), which gates flag=4 on that config for every viewer. The owner always sees
 * their own age (self → Private clearance), an intentional divergence from OpenPNE 3's getAge(true).
 */
class VisibleAge
{
    public function __construct(private PresetProfileService $presets) {}

    public function __invoke(?Member $viewer, Member $owner): ?int
    {
        $ageVisibility = $owner->preference(PreferenceKey::AgeVisibility);

        // A web-public (Open) age conveys visibility only while the SNS allows web-public age; when
        // disabled it is shown to nobody (not even members), mirroring OpenPNE 3's getAge() gating
        // flag=4 on is_allow_web_public_flag_age.
        if ($ageVisibility === Visibility::Open && ! AgeVisibility::allowsWebPublic()) {
            return null;
        }

        if ($viewer === null) {
            $clearance = Visibility::Open; // a guest only ever reaches an Open age (gated above)
        } else {
            // A blocked viewer must not see the owner's age, mirroring ShowProfile's query-layer
            // block check (defense in depth; the profile page also 404s at the controller).
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
