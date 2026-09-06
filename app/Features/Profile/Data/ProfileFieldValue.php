<?php

namespace App\Features\Profile\Data;

use App\Models\MemberProfile;
use App\Models\Profile;
use App\Support\Visibility;
use Illuminate\Support\Collection;

final class ProfileFieldValue
{
    /** @param Collection<int, MemberProfile> $values */
    public function __construct(
        public readonly Profile $profile,
        public readonly Collection $values,
    ) {}

    /** The audience the field is shown to: the row's own choice when the field lets the member pick, else the field's default. */
    public function visibility(): Visibility
    {
        if ($this->profile->is_edit_public_flag) {
            /** @var MemberProfile $row a multi-value field stores the flag on every row alike */
            $row = $this->values->first();

            return $row->visibility ?? $this->profile->default_visibility;
        }

        return $this->profile->default_visibility;
    }

    /** The parenthesised audience the owner reads after the value (OpenPNE 3 _profileListBox.php), or null. */
    public function ownerCaption(): ?string
    {
        return $this->visibility()->ownerCaption((bool) $this->profile->is_public_web);
    }

    public function display(string $lang): string
    {
        return $this->values
            ->map(fn (MemberProfile $value): string => $value->displayValue($lang))
            ->filter(fn (string $text): bool => $text !== '')
            ->implode(', ');
    }
}
