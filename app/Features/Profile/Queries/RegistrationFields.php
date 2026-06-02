<?php

namespace App\Features\Profile\Queries;

use App\Features\Profile\Data\EditableField;
use App\Models\Profile;
use Illuminate\Support\Collection;

/**
 * The profile fields shown on the registration form (is_disp_regist, ordered by sort order), each
 * with an empty initial value and the field default visibility. Registration collects values
 * only; per-value visibility follows the field default and is changed later via profile edit.
 */
class RegistrationFields
{
    /** @return Collection<int, EditableField> */
    public function __invoke(): Collection
    {
        return Profile::query()
            ->with(['translations', 'options.translations'])
            ->where('is_disp_regist', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Profile $profile): EditableField => new EditableField(
                $profile,
                $profile->form_type === 'checkbox' ? [] : '',
                $profile->default_visibility,
            ));
    }
}
