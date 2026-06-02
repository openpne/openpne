<?php

namespace App\Features\Profile;

use App\Models\Profile;
use App\Services\CountryListService;
use App\Services\PresetProfileService;
use App\Services\RegionListService;
use Illuminate\Validation\Rule;

/**
 * Builds the validation rules for one configurable profile field's submitted value, keyed by
 * `profile.{id}` (+ `profile.{id}.*` for a checkbox). Shared by profile-edit and registration so
 * both validate identically: preset choice keys vs custom option ids, the country/region sets,
 * date bounds, regexp/min/max, and uniqueness. The caller adds the per-value visibility rule
 * (edit only) and any non-profile keys.
 */
class ProfileFieldRules
{
    public function __construct(private PresetProfileService $presets) {}

    /**
     * @param  int|null  $ignoreMemberId  member excluded from a unique field's check (null at
     *                                    registration, where the member does not exist yet)
     * @return array<string, array<int, mixed>>
     */
    public function forValue(Profile $profile, ?int $ignoreMemberId = null): array
    {
        $key = 'profile.'.$profile->getKey();

        if ($profile->form_type === 'checkbox') {
            return [
                $key => [$profile->is_required ? 'required' : 'nullable', 'array'],
                "{$key}.*" => [Rule::in($profile->options->pluck('id')->all())],
            ];
        }

        return [$key => $this->valueRules($profile, $ignoreMemberId)];
    }

    /** A unique input/textarea rejects a value another member already holds (OpenPNE 3 opValidatorProfile). */
    public function isUniqueText(Profile $profile): bool
    {
        return $profile->is_unique && in_array($profile->form_type, ['input', 'textarea'], true);
    }

    /** @return array<int, mixed> */
    private function valueRules(Profile $profile, ?int $ignoreMemberId): array
    {
        $rules = [$profile->is_required ? 'required' : 'nullable'];

        switch ($profile->form_type) {
            case 'select':
            case 'radio':
                $rules[] = Rule::in($this->choiceIds($profile));
                break;
            case 'date':
                $rules[] = 'date';
                // Enforce the admin-configured bounds (OpenPNE 3 set these on the date widget).
                if ($profile->value_min !== null && $profile->value_min !== '') {
                    $rules[] = 'after_or_equal:'.$profile->value_min;
                }
                if ($profile->value_max !== null && $profile->value_max !== '') {
                    $rules[] = 'before_or_equal:'.$profile->value_max;
                }
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
                    $rules[] = Rule::unique('member_profiles', 'value')->where(function ($query) use ($profile, $ignoreMemberId) {
                        $query->where('profile_id', $profile->getKey());
                        if ($ignoreMemberId !== null) {
                            $query->where('member_id', '!=', $ignoreMemberId);
                        }

                        return $query;
                    });
                }
                break;
        }

        return $rules;
    }

    /** @return list<string> */
    private function choiceIds(Profile $profile): array
    {
        if ($this->presets->usesValueColumnForChoice($profile)) {
            return array_map(fn (array $c): string => $c['id'], $this->presets->choicesFor($profile));
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
