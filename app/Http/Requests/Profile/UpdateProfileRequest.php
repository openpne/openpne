<?php

namespace App\Http\Requests\Profile;

use App\Features\Profile\Data\ProfileFormData;
use App\Models\Member;
use App\Models\Profile;
use App\Services\CountryListService;
use App\Services\PresetProfileService;
use App\Services\RegionListService;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        $id = $profile->getKey();
        $key = "profile.{$id}";
        $rules = [];

        if ($profile->form_type === 'checkbox') {
            $rules[$key] = [$profile->is_required ? 'required' : 'nullable', 'array'];
            $rules["{$key}.*"] = [Rule::in($profile->options->pluck('id')->all())];
        } else {
            $rules[$key] = $this->valueRules($profile);
        }

        if ($profile->is_edit_public_flag) {
            $allowed = array_map(fn (Visibility $v): int => $v->value, $profile->visibilityOptions());
            $rules["visibility.{$id}"] = ['nullable', Rule::in($allowed)];
        }

        return $rules;
    }

    /** @return array<int, mixed> */
    private function valueRules(Profile $profile): array
    {
        $rules = [$profile->is_required ? 'required' : 'nullable'];

        switch ($profile->form_type) {
            case 'select':
            case 'radio':
                $rules[] = Rule::in($this->choiceIds($profile));
                break;
            case 'date':
                $rules[] = 'date';
                break;
            case 'country_select':
                $rules[] = Rule::in(array_keys(app(CountryListService::class)->getOptions()));
                break;
            case 'region_select':
                $rules[] = Rule::in(app(RegionListService::class)->flattenOptions($profile->value_type));
                break;
            default: // input / textarea
                $rules[] = 'string';
                if ($profile->value_regexp) {
                    $rules[] = 'regex:'.$this->normalizeRegex($profile->value_regexp);
                }
                if ($profile->value_min !== null && $profile->value_min !== '') {
                    $rules[] = 'min:'.(int) $profile->value_min;
                }
                if ($profile->value_max !== null && $profile->value_max !== '') {
                    $rules[] = 'max:'.(int) $profile->value_max;
                }
                if ($this->isUniqueText($profile)) {
                    // OpenPNE 3 (opValidatorProfile) rejects a value already held by another
                    // member for a unique input/textarea field; ignore the member's own rows.
                    $rules[] = Rule::unique('member_profiles', 'value')->where(
                        fn ($query) => $query
                            ->where('profile_id', $profile->getKey())
                            ->where('member_id', '!=', $this->user()->getKey()),
                    );
                }
                break;
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $messages = [];
        foreach ($this->editableProfiles() as $profile) {
            if ($this->isUniqueText($profile)) {
                $messages["profile.{$profile->getKey()}.unique"] = __('This value is already in use.');
            }
        }

        return $messages;
    }

    private function isUniqueText(Profile $profile): bool
    {
        return $profile->is_unique && in_array($profile->form_type, ['input', 'textarea'], true);
    }

    /** @return list<string> */
    private function choiceIds(Profile $profile): array
    {
        $presets = app(PresetProfileService::class);

        if ($presets->usesValueColumnForChoice($profile)) {
            return array_map(fn (array $c): string => $c['id'], $presets->choicesFor($profile));
        }

        return $profile->options->pluck('id')->map(fn ($id): string => (string) $id)->all();
    }

    private function normalizeRegex(string $pattern): string
    {
        if ($pattern !== '' && $pattern[0] !== '/' && $pattern[0] !== '#') {
            return '/'.$pattern.'/';
        }

        return $pattern;
    }
}
