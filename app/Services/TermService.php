<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TermService
{
    private const CACHE_TTL = 3600;

    /**
     * Replace `%name%` placeholders in a translated string with the
     * resolved term value for the given locale. `%Name%` capitalises in
     * English locales (no-op in Japanese); `%names%` pluralises in English.
     */
    public function replace(string $text, string $locale): string
    {
        if (! str_contains($text, '%')) {
            return $text;
        }

        $terms = $this->getTerms($locale);
        $isJa = str_starts_with($locale, 'ja');

        return preg_replace_callback('/%([a-zA-Z_]+)%/', function (array $matches) use ($terms, $isJa): string {
            $raw = $matches[1];
            $fronting = ctype_upper($raw[0]);
            $name = $fronting ? lcfirst($raw) : $raw;

            if (array_key_exists($name, $terms)) {
                $value = $terms[$name];

                return $fronting && ! $isJa ? Str::ucfirst($value) : $value;
            }

            $singular = Str::singular($name);
            if ($singular !== $name && array_key_exists($singular, $terms)) {
                $value = $isJa ? $terms[$singular] : Str::plural($terms[$singular]);

                return $fronting && ! $isJa ? Str::ucfirst($value) : $value;
            }

            return $matches[0];
        }, $text) ?? $text;
    }

    /**
     * Resolved term map for a locale: defaults merged with admin overrides.
     *
     * @return array<string, string>
     */
    public function getTerms(string $locale): array
    {
        return Cache::remember("terms.{$locale}", self::CACHE_TTL, function () use ($locale): array {
            $defaults = self::defaults($locale);
            $overrides = DB::table('term_overrides')
                ->where('locale', $locale)
                ->pluck('value', 'name')
                ->all();

            return array_merge($defaults, $overrides);
        });
    }

    /**
     * Defaults shipped with the application for a locale. The key set defines
     * which terms an administrator may override.
     *
     * @return array<string, string>
     */
    public static function defaults(string $locale): array
    {
        $path = lang_path("{$locale}/terms.php");
        if (! is_file($path)) {
            return [];
        }

        /** @var array<string, string> $values */
        $values = require $path;

        return $values;
    }

    /**
     * Clear cached term maps. Call after persisting changes from the admin UI.
     */
    public function clearCache(): void
    {
        foreach (SetLocale::SUPPORTED_LOCALES as $locale) {
            Cache::forget("terms.{$locale}");
        }
    }
}
