<?php

namespace App\Features\Profile\Serializers;

use App\Features\Profile\Data\EditableField;
use App\Models\Profile;
use App\Services\CountryListService;
use App\Services\RegionListService;
use App\Support\Visibility;
use Illuminate\Support\Collection;

/** Modern surface shape for the profile-edit form. */
class ProfileFormSerializer
{
    /**
     * @param  Collection<int, EditableField>  $fields
     * @return array{name: string, fields: list<array<string, mixed>>}
     */
    public static function form(string $memberName, Collection $fields, string $lang): array
    {
        return [
            'name' => $memberName,
            'fields' => self::fields($fields, $lang),
        ];
    }

    /**
     * @param  Collection<int, EditableField>  $fields
     * @return list<array<string, mixed>>
     */
    public static function fields(Collection $fields, string $lang): array
    {
        return $fields->map(fn (EditableField $field): array => self::field($field, $lang))->values()->all();
    }

    /** @return array<string, mixed> */
    private static function field(EditableField $field, string $lang): array
    {
        $profile = $field->profile;

        return [
            'id' => $profile->getKey(),
            'name' => $profile->name,
            'caption' => $profile->getCaption($lang),
            'info' => $profile->getInfo($lang),
            'form_type' => $profile->form_type,
            'is_required' => (bool) $profile->is_required,
            'is_edit_public_flag' => (bool) $profile->is_edit_public_flag,
            'options' => $profile->choices($lang),
            'countries' => $profile->form_type === 'country_select' ? self::countries($lang) : null,
            'regions' => $profile->form_type === 'region_select' ? self::regions($profile, $lang) : null,
            'value' => is_array($field->value) ? array_map(fn ($v): string => (string) $v, $field->value) : $field->value,
            'visibility' => $field->visibility->value,
            'visibilityOptions' => $profile->is_edit_public_flag
                ? array_map(fn (Visibility $v): array => ['value' => $v->value, 'label' => __($v->label())], $profile->visibilityOptions())
                : [],
        ];
    }

    /** @return list<array{value: string, label: string}> */
    private static function countries(string $lang): array
    {
        return self::pairs(app(CountryListService::class)->getOptions($lang));
    }

    /** @return list<array{country: string, options: list<array{value: string, label: string}>}> */
    private static function regions(Profile $profile, string $lang): array
    {
        $options = app(RegionListService::class)->getOptions($profile->value_type, $lang);
        $valueType = ($profile->value_type === '' || $profile->value_type === null) ? 'string' : $profile->value_type;

        if ($valueType !== 'string') {
            /** @var array<string, string> $options */
            return [['country' => '', 'options' => self::pairs($options)]];
        }

        $groups = [];
        foreach ($options as $countryName => $regions) {
            $groups[] = ['country' => (string) $countryName, 'options' => self::pairs($regions)];
        }

        return $groups;
    }

    /**
     * @param  array<string, string>  $map
     * @return list<array{value: string, label: string}>
     */
    private static function pairs(array $map): array
    {
        $out = [];
        foreach ($map as $value => $label) {
            $out[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return $out;
    }
}
