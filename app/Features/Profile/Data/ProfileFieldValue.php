<?php

namespace App\Features\Profile\Data;

use App\Models\MemberProfile;
use App\Models\Profile;
use Illuminate\Support\Collection;

/**
 * One profile field shown on a member's page: the field definition plus the member's
 * value rows (one row for single-value fields, several for a checkbox).
 */
final class ProfileFieldValue
{
    /** @param Collection<int, MemberProfile> $values */
    public function __construct(
        public readonly Profile $profile,
        public readonly Collection $values,
    ) {}

    /** Localised display value(s); a multi-value field joins its rows. */
    public function display(string $lang): string
    {
        return $this->values
            ->map(fn (MemberProfile $value): string => $value->displayValue($lang))
            ->filter(fn (string $text): bool => $text !== '')
            ->implode(', ');
    }
}
