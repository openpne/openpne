<?php

namespace App\Features\Profile\Queries;

use App\Features\Block\BlockLookup;
use App\Features\Profile\Data\ProfileFieldValue;
use App\Models\Member;
use App\Models\MemberProfile;
use App\Support\Visibility;
use Illuminate\Support\Collection;

/** Null means the owner blocks the viewer; the profile page turns that into a 404. */
class ShowProfile
{
    /** @return Collection<int, ProfileFieldValue>|null */
    public function __invoke(?Member $viewer, Member $owner, string $lang): ?Collection
    {
        if ($viewer !== null && ! $viewer->is($owner) && BlockLookup::ownerBlocksViewer($owner, $viewer)) {
            return null;
        }

        $isGuest = $viewer === null;
        $clearance = $isGuest ? Visibility::Open : Visibility::clearanceFor($viewer, $owner);

        return $owner->memberProfiles()
            ->with(['profile.translations', 'option.translations'])
            ->get()
            ->groupBy('profile_id')
            ->map(fn (Collection $rows): ProfileFieldValue => new ProfileFieldValue($rows->first()->profile, $rows))
            ->filter(fn (ProfileFieldValue $field): bool => $this->effectiveVisibility($field)->value <= $clearance->value)
            ->filter(fn (ProfileFieldValue $field): bool => ! $isGuest || $field->profile->is_public_web)
            ->filter(fn (ProfileFieldValue $field): bool => $field->display($lang) !== '')
            ->sortBy(fn (ProfileFieldValue $field): int => $field->profile->sort_order ?? PHP_INT_MAX)
            ->values();
    }

    private function effectiveVisibility(ProfileFieldValue $field): Visibility
    {
        $profile = $field->profile;

        // A multi-value field stores the flag on every row alike, so the first row is enough.
        /** @var MemberProfile $row */
        $row = $field->values->first();

        if ($profile->is_edit_public_flag) {
            return $row->visibility ?? $profile->default_visibility;
        }

        return $profile->default_visibility;
    }
}
