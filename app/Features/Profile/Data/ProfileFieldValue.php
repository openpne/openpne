<?php

namespace App\Features\Profile\Data;

use App\Models\MemberProfile;
use App\Models\Profile;
use Illuminate\Support\Collection;

final class ProfileFieldValue
{
    /** @param Collection<int, MemberProfile> $values */
    public function __construct(
        public readonly Profile $profile,
        public readonly Collection $values,
    ) {}

    public function display(string $lang): string
    {
        return $this->values
            ->map(fn (MemberProfile $value): string => $value->displayValue($lang))
            ->filter(fn (string $text): bool => $text !== '')
            ->implode(', ');
    }
}
