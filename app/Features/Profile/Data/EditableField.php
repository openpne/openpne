<?php

namespace App\Features\Profile\Data;

use App\Models\Profile;
use App\Support\Visibility;

/**
 * One field on the profile-edit form: the field definition plus the member's current input.
 * `value` is the value the form control should preselect — a list of option ids for a checkbox,
 * an option id for a custom select/radio, the choice key for a preset select/radio, a Y-m-d for
 * a date, or the raw string otherwise. `visibility` is the dropdown's current selection (the
 * stored per-value flag, or the field default when none is stored).
 */
final readonly class EditableField
{
    /** @param string|list<int> $value */
    public function __construct(
        public Profile $profile,
        public string|array $value,
        public Visibility $visibility,
    ) {}
}
