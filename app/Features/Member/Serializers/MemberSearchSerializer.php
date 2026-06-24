<?php

namespace App\Features\Member\Serializers;

use App\Models\Member;
use App\Models\Profile;
use App\Services\CountryListService;
use App\Services\RegionListService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/** Modern surface shapes for member search: the result paginator and the search form fields. */
class MemberSearchSerializer
{
    /**
     * @param  LengthAwarePaginator<int, Member>  $members
     * @return array{data: list<array{id: int, name: string, avatarUrl: ?string}>, meta: array{currentPage: int, lastPage: int, perPage: int, total: int}}
     */
    public static function paginator(LengthAwarePaginator $members): array
    {
        return [
            'data' => array_map([self::class, 'memberRow'], $members->items()),
            'meta' => [
                'currentPage' => $members->currentPage(),
                'lastPage' => $members->lastPage(),
                'perPage' => $members->perPage(),
                'total' => $members->total(),
            ],
        ];
    }

    /**
     * @param  Collection<int, Profile>  $profiles
     * @return list<array<string, mixed>>
     */
    public static function formFields(Collection $profiles, string $lang, string $birthdayName): array
    {
        // The birthday renders as a month/day picker (its year is searched via the age criterion), so
        // it gets a synthetic 'birthday' formType the front-end switches on.
        return $profiles->map(fn (Profile $profile): array => [
            'id' => $profile->getKey(),
            'name' => $profile->name,
            'caption' => $profile->getCaption($lang),
            'formType' => $profile->name === $birthdayName ? 'birthday' : $profile->form_type,
            'options' => $profile->choices($lang),
            'countries' => $profile->form_type === 'country_select' ? self::countries($lang) : null,
            'regions' => $profile->form_type === 'region_select' ? self::regions($profile, $lang) : null,
        ])->values()->all();
    }

    /** @return array{id: int, name: string, avatarUrl: ?string} */
    private static function memberRow(Member $member): array
    {
        return [
            'id' => $member->getKey(),
            'name' => $member->name,
            'avatarUrl' => $member->avatar?->file?->thumbnailUrl(76, 76, square: true),
        ];
    }

    // Country/region option shapes mirror Profile's edit-form serializer; the small amount of
    // duplication keeps the search feature self-contained.

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
