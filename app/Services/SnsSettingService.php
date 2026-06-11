<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\SnsSettingKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads the global SNS settings (`sns_settings`), resolving each key to its stored override or its
 * App\Support\SnsSettingKey default. The whole override map is cached; the admin page calls
 * clearCache() after persisting.
 */
class SnsSettingService
{
    private const CACHE_KEY = 'sns_settings';

    private const CACHE_TTL = 3600;

    /** Resolved value for a key: the stored override, or the key default when no row exists. */
    public function get(SnsSettingKey $key): mixed
    {
        return $key->decode($this->overrides()[$key->value] ?? null);
    }

    /**
     * Stored overrides keyed by setting key, cached. Guards against the table not existing yet so a
     * pre-migrate / console boot resolves to defaults instead of throwing.
     *
     * @return array<string, string>
     */
    private function overrides(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            if (! Schema::hasTable('sns_settings')) {
                return [];
            }

            return DB::table('sns_settings')->pluck('value', 'key')->all();
        });
    }

    /** Drop the cached overrides. Call after persisting changes from the admin UI. */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
