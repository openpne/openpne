<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Symfony\Component\Intl\Countries;

/**
 * Country list for the country_select profile form type.
 *
 * Supplies the CLDR country names (~250 ISO 3166-1 alpha-2 codes) localised for the UI locale,
 * the same role OpenPNE 3 filled with sfCultureInfo::getCountries(). Accepts both the app
 * locale ('ja'/'en') and the Doctrine translation lang ('ja_JP').
 */
class CountryListService
{
    private const LANG_MAP = ['ja' => 'ja', 'ja_JP' => 'ja', 'en' => 'en'];

    /** @return array<string, string> ISO 3166-1 alpha-2 code => localised country name */
    public function getOptions(?string $locale = null): array
    {
        $lang = self::LANG_MAP[$locale ?? app()->getLocale()] ?? 'en';

        return Cache::rememberForever("country_options.{$lang}", fn (): array => Countries::getNames($lang));
    }

    public function getName(string $code, ?string $locale = null): string
    {
        return $this->getOptions($locale)[$code] ?? $code;
    }
}
