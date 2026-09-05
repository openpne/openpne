<?php

namespace App\Features\Profile\Data;

use App\Models\Profile;
use App\Support\Visibility;

/**
 * `value` is what the control preselects: option ids for a checkbox, an option id for a custom
 * select/radio, the choice key for a preset one, `Y-m-d` for a date, the raw string otherwise.
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
