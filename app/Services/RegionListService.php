<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Lang;

/**
 * The stored value is always the English source region name from config/regions.php, so saved data
 * is locale-independent and only the display label is translated.
 */
class RegionListService
{
    /**
     * Options for a <select>.
     *
     * @return array<string, string|array<string, string>>
     */
    public function getOptions(?string $valueType, ?string $locale = null): array
    {
        $regions = (array) config('regions', []);
        $valueType = ($valueType === '' || $valueType === null) ? 'string' : $valueType;
        $cacheLocale = $locale ?? app()->getLocale();
        $localeCode = $this->normalizeLocale($cacheLocale);

        if ($valueType !== 'string') {
            $list = $regions[$valueType] ?? [];

            return Cache::rememberForever(
                "region_options.country.{$valueType}.{$localeCode}",
                fn (): array => $this->buildFlatOptions($list, $localeCode),
            );
        }

        return Cache::rememberForever(
            "region_options.grouped.{$cacheLocale}",
            function () use ($regions, $cacheLocale, $localeCode): array {
                $countryNames = app(CountryListService::class)->getOptions($cacheLocale);
                $grouped = [];
                foreach ($regions as $code => $list) {
                    if (empty($list)) {
                        continue;
                    }
                    $grouped[$countryNames[$code] ?? $code] = $this->buildFlatOptions($list, $localeCode);
                }
                ksort($grouped);

                return $grouped;
            },
        );
    }

    /**
     * The flattened set of valid values, for validation.
     *
     * @return list<string>
     */
    public function flattenOptions(?string $valueType): array
    {
        $regions = (array) config('regions', []);
        $valueType = ($valueType === '' || $valueType === null) ? 'string' : $valueType;

        if ($valueType !== 'string') {
            return array_values($regions[$valueType] ?? []);
        }

        $all = [];
        foreach ($regions as $list) {
            foreach ($list as $r) {
                $all[] = $r;
            }
        }

        return array_values(array_unique($all));
    }

    /** The localised label for a stored region source name (falls back to the source). */
    public function label(string $name, ?string $locale = null): string
    {
        return $this->translateRegion($name, $this->normalizeLocale($locale ?? app()->getLocale()));
    }

    /**
     * @param  list<string>  $list  English source region names
     * @return array<string, string> [source => localised label]
     */
    private function buildFlatOptions(array $list, string $localeCode): array
    {
        $out = [];
        foreach ($list as $r) {
            $out[$r] = $this->translateRegion($r, $localeCode);
        }

        return $out;
    }

    private function translateRegion(string $name, string $localeCode): string
    {
        $key = 'regions.'.$name;

        // Check the exact locale only (no fallback): the English source string is the intended
        // fallback, so an untranslated key must not resolve to APP_FALLBACK_LOCALE's translation
        // (e.g. an English label coming back as Japanese when ja is the fallback locale).
        return Lang::has($key, $localeCode, fallback: false) ? (string) trans($key, [], $localeCode) : $name;
    }

    private function normalizeLocale(string $locale): string
    {
        return str_starts_with($locale, 'ja') ? 'ja' : 'en';
    }
}
