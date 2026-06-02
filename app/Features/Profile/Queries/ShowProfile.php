<?php

namespace App\Features\Profile\Queries;

use App\Features\Block\BlockLookup;
use App\Features\Profile\Data\ProfileFieldValue;
use App\Models\Member;
use App\Models\MemberProfile;
use App\Support\Visibility;
use Illuminate\Support\Collection;

/**
 * The profile fields of $owner visible to $viewer, ordered by the field's sort order.
 *
 * Returns null when the owner blocks the viewer (the whole page is then a 404), matching
 * Diary's owner→viewer block. Each field's effective visibility (per-value flag, or the
 * field default when the field is not per-value editable / has no value flag) is compared
 * to the viewer's clearance via the shared monotonic Visibility scale.
 */
class ShowProfile
{
    /** @return Collection<int, ProfileFieldValue>|null */
    public function __invoke(Member $viewer, Member $owner): ?Collection
    {
        if (! $viewer->is($owner) && BlockLookup::ownerBlocksViewer($owner, $viewer)) {
            return null;
        }

        $clearance = Visibility::clearanceFor($viewer, $owner);

        return $owner->memberProfiles()
            ->with(['profile.translations', 'option.translations'])
            ->get()
            ->groupBy('profile_id')
            ->map(fn (Collection $rows): ProfileFieldValue => new ProfileFieldValue($rows->first()->profile, $rows))
            ->filter(fn (ProfileFieldValue $field): bool => $this->effectiveVisibility($field)->value <= $clearance->value)
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
