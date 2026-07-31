<?php

namespace App\Features\Profile\Queries;

use App\Features\Profile\Data\EditableField;
use App\Models\Profile;
use App\Support\Visibility;
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
                $this->initialVisibility($profile),
            ));
    }

    /**
     * The field default, clamped to an audience the form offers. A registrant has no stored value to
     * keep, so an admin default the picker dropped (Friends while friends are off, Open on a field
     * that is not web-public) has no sticky claim — and leaving it selected would either post a
     * value the rule rejects or, in Classic, silently submit whichever option the browser picked
     * first. Members is the nearest offered audience, and it is on screen before they submit.
     */
    private function initialVisibility(Profile $profile): Visibility
    {
        $default = $profile->default_visibility;

        return in_array($default, $profile->visibilityOptions(), true) ? $default : Visibility::Members;
    }
}
