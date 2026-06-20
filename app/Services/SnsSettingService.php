<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\SettingGroup;
use App\Support\SnsSettingKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads the global SNS settings (`sns_settings`), resolving each key to its stored override or its
 * App\Support\SnsSettingKey default. The admin pages call clearCache() after persisting.
 *
 * The overrides are cached in tiers so the hot path stays small: the identity / auth / gadget settings
 * (read on every request — sns_name() is shared to Modern too) never deserialize the large design
 * blobs. The Classic HTML-insertion / footer keys load only when a Classic page renders. The custom
 * CSS document is isolated entirely — its body has its own entry, read only by the CSS endpoint, and a
 * separate boolean flag answers "is any custom CSS set" so a Classic render decides whether to <link>
 * it without ever loading the body.
 */
class SnsSettingService
{
    /** Identity / auth / gadget-layout keys — the hot path (sns_name(), CAPTCHA, …). */
    private const CORE_CACHE_KEY = 'sns_settings';

    /** Classic-render design keys (HTML insertion slots + footer); read only while rendering Classic. */
    private const DESIGN_CACHE_KEY = 'sns_settings:design';

    /** The custom CSS document body, isolated so it never enters a shared map (read only by the endpoint). */
    private const CSS_CACHE_KEY = 'sns_settings:customizing_css';

    /** Boolean "is custom CSS set" flag, cached apart from the body so a Classic render never loads it. */
    private const CSS_PRESENT_CACHE_KEY = 'sns_settings:customizing_css_present';

    private const CACHE_TTL = 3600;

    /** Resolved value for a key: the stored override, or the key default when no row exists. */
    public function get(SnsSettingKey $key): mixed
    {
        if ($key === SnsSettingKey::CustomCss) {
            return $key->decode($this->customCss());
        }

        $map = $key->group() === SettingGroup::Design ? $this->designOverrides() : $this->coreOverrides();

        return $key->decode($map[$key->value] ?? null);
    }

    /**
     * Whether a non-empty custom CSS document is set — the cheap check the layout uses to decide
     * whether to emit the <link>. Cached as a boolean of its own (an existence query, not the body),
     * so a Classic render never pulls the CSS into memory just to test presence.
     */
    public function hasCustomCss(): bool
    {
        return Cache::remember(self::CSS_PRESENT_CACHE_KEY, self::CACHE_TTL, function (): bool {
            if (! Schema::hasTable('sns_settings')) {
                return false;
            }

            return DB::table('sns_settings')
                ->where('key', SnsSettingKey::CustomCss->value)
                ->where('value', '<>', '')
                ->exists();
        });
    }

    /** Drop every cached tier. Call after persisting changes from an admin page. */
    public function clearCache(): void
    {
        Cache::forget(self::CORE_CACHE_KEY);
        Cache::forget(self::DESIGN_CACHE_KEY);
        Cache::forget(self::CSS_CACHE_KEY);
        Cache::forget(self::CSS_PRESENT_CACHE_KEY);
    }

    /**
     * Stored overrides for the non-design keys, keyed by setting key. Guards against the table not
     * existing yet so a pre-migrate / console boot resolves to defaults instead of throwing.
     *
     * @return array<string, string>
     */
    private function coreOverrides(): array
    {
        return Cache::remember(self::CORE_CACHE_KEY, self::CACHE_TTL, function (): array {
            if (! Schema::hasTable('sns_settings')) {
                return [];
            }

            return DB::table('sns_settings')
                ->whereNotIn('key', $this->designKeyValues())
                ->pluck('value', 'key')
                ->all();
        });
    }

    /**
     * Stored overrides for the Classic-render design keys (HTML insertion + footer), excluding the
     * custom CSS document which is served separately.
     *
     * @return array<string, string>
     */
    private function designOverrides(): array
    {
        return Cache::remember(self::DESIGN_CACHE_KEY, self::CACHE_TTL, function (): array {
            if (! Schema::hasTable('sns_settings')) {
                return [];
            }

            return DB::table('sns_settings')
                ->whereIn('key', $this->classicRenderKeyValues())
                ->pluck('value', 'key')
                ->all();
        });
    }

    /** The stored custom CSS body, or '' when unset; read only via get(CustomCss) — the CSS endpoint and admin form. */
    private function customCss(): string
    {
        return Cache::remember(self::CSS_CACHE_KEY, self::CACHE_TTL, function (): string {
            if (! Schema::hasTable('sns_settings')) {
                return '';
            }

            return (string) DB::table('sns_settings')
                ->where('key', SnsSettingKey::CustomCss->value)
                ->value('value');
        });
    }

    /** @return list<string> every design key's stored name (excluded from the core map). */
    private function designKeyValues(): array
    {
        return array_map(
            static fn (SnsSettingKey $key): string => $key->value,
            SnsSettingKey::inGroup(SettingGroup::Design),
        );
    }

    /** @return list<string> design keys read at Classic render time (design minus the CSS document). */
    private function classicRenderKeyValues(): array
    {
        return array_values(array_filter(
            $this->designKeyValues(),
            static fn (string $value): bool => $value !== SnsSettingKey::CustomCss->value,
        ));
    }
}
