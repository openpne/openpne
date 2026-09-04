<?php

namespace App\Http\Requests\Profile;

use App\Features\Member\MemberNameRules;
use App\Features\Profile\AgeVisibility;
use App\Features\Profile\Data\ProfileFormData;
use App\Features\Profile\ProfileFieldRules;
use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\Profile;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A per-value visibility is accepted only for a member-editable field, restricted to the field's
 * offered choices (Open only when web-public) plus the audience the value already carries.
 */
class UpdateProfileRequest extends FormRequest
{
    /** @var Collection<int, Profile>|null */
    private ?Collection $profilesCache = null;

    /** @var array<int, Visibility|null>|null */
    private ?array $storedVisibilities = null;

    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = ['name' => MemberNameRules::rules()];

        // Submitted only when the Modern form offers the age block (site has a birthday item);
        // the write is additionally gated in the controller, so a crafted value without a
        // birthday item validates but persists nothing.
        $rules['age_visibility'] = ['sometimes', 'required', AgeVisibility::ruleFor($this->user())];

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

        return $rules->forValue($profile, $this->user()->getKey())
            + $rules->visibilityRule($profile, $this->storedVisibilities()[$profile->getKey()] ?? null);
    }

    /**
     * The audience each of the member's values already carries, keyed by field id — the same sticky
     * current EditProfileFields kept offering, so a re-posted form is accepted as-is instead of
     * being rejected (or clamped) into a wider audience.
     *
     * @return array<int, Visibility|null>
     */
    private function storedVisibilities(): array
    {
        return $this->storedVisibilities ??= $this->user()->memberProfiles()->get()
            ->reduce(function (array $carry, MemberProfile $row): array {
                // A checkbox stores one row per choice, all written with the same audience.
                $carry[$row->profile_id] ??= $row->visibility;

                return $carry;
            }, []);
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
