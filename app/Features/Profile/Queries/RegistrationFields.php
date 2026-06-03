<?php

namespace App\Features\Profile\Queries;

use App\Features\Profile\Data\EditableField;
use App\Models\Profile;
use Illuminate\Support\Collection;

/**
 * The profile fields shown on the registration form (is_disp_regist, ordered by sort order), each
 * seeded with an empty value and the field default visibility. The default visibility is the
 * initial selection; a member-editable field (is_edit_public_flag) lets the registrant change it,
 * validated and persisted by CreateNewMember like the edit form.
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
