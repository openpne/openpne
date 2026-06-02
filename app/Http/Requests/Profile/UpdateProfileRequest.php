<?php

namespace App\Http\Requests\Profile;

use App\Features\Profile\Data\ProfileFormData;
use App\Features\Profile\ProfileFieldRules;
use App\Models\Member;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a profile-edit submission. Rules are built per field from its form_type:
 * select/radio → the option ids (preset choice keys or custom option ids), country/region →
 * the valid code/region set, date → date, input/textarea → string + the field's regexp/min/max.
 * A per-value visibility is accepted only for member-editable fields, restricted to that field's
 * offered choices (Open only when web-public).
 */
class UpdateProfileRequest extends FormRequest
{
    /** @var Collection<int, Profile>|null */
    private ?Collection $profilesCache = null;

    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = ['name' => ['required', 'string', 'max:255']];

        foreach ($this->editableProfiles() as $profile) {
            $rules += $this->rulesForProfile($profile);
        }

        return $rules;
    }

    /** @return Collection<int, Profile> */
    public function editableProfiles(): Collection
    {
        return $this->profilesCache ??= Profile::query()
            ->with('options')
            ->where('is_disp_config', true)
            ->orderBy('sort_order')
            ->get();
    }

    public function toData(): ProfileFormData
    {
        $validated = $this->validated();

        return new ProfileFormData(
            name: $validated['name'],
            values: $validated['profile'] ?? [],
            visibilities: array_map(
                fn ($v): ?int => $v === null ? null : (int) $v,
                $validated['visibility'] ?? [],
            ),
        );
    }

    /** @return array<string, array<int, mixed>> */
    private function rulesForProfile(Profile $profile): array
    {
        $rules = app(ProfileFieldRules::class);

        return $rules->forValue($profile, $this->user()->getKey()) + $rules->visibilityRule($profile);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $rules = app(ProfileFieldRules::class);
        $messages = [];
        foreach ($this->editableProfiles() as $profile) {
            if ($rules->isUniqueText($profile)) {
                $messages["profile.{$profile->getKey()}.unique"] = __('This value is already in use.');
            }
        }

        return $messages;
    }
}
