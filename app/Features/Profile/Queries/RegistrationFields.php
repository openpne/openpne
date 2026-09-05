<?php

namespace App\Features\Profile\Queries;

use App\Features\Profile\Data\EditableField;
use App\Models\Profile;
use App\Support\Visibility;
use Illuminate\Support\Collection;

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
     * A registrant has no stored value to keep, so an admin default the picker dropped (Friends while
     * friends are off, Open on a field that is not web-public) is clamped to Members. Left selected it
     * would post a value the rule rejects, or in Classic submit whichever option the browser picked.
     */
    private function initialVisibility(Profile $profile): Visibility
    {
        $default = $profile->default_visibility;

        return in_array($default, $profile->visibilityOptions(), true) ? $default : Visibility::Members;
    }
}
