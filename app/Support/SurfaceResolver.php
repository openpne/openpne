<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Decides whether a canonical feature route renders the Classic or Modern
 * surface, and maps a canonical route name to its Modern sibling on redirect.
 * Shared by every feature controller that serves both surfaces (Friend, Block).
 *
 * Priority (highest first): feature modern status > explicit /m/* route default
 * > tenant mode > member migration UI override > tenant default. See
 * worklog classic-compatibility-contract §Canonical response selection.
 */
class SurfaceResolver
{
    public const CLASSIC = 'classic';

    public const MODERN = 'modern';

    public static function resolve(Request $request, string $feature): string
    {
        if (config("features.{$feature}.modern_status", 'native') !== 'native') {
            return self::CLASSIC;
        }

        if ($request->route('surface') === self::MODERN) {
            return self::MODERN;
        }

        if (config('openpne.tenant_mode', 'mixed') === 'modern_only') {
            return self::MODERN;
        }

        $override = $request->session()->get('migration_ui_override');
        if (in_array($override, [self::CLASSIC, self::MODERN], true)) {
            return $override;
        }

        return config('openpne.tenant_default_surface', self::CLASSIC);
    }

    /**
     * `friend.list` -> `friend.modern.list` when the request is on a Modern
     * route, so a post-submit redirect lands on the same surface it came from.
     * Requires the convention canonical `{feature}.{rest}` <-> Modern
     * `{feature}.modern.{rest}`; every feature consuming the resolver must
     * name its routes that way.
     */
    public static function redirectName(Request $request, string $canonicalName): string
    {
        if ($request->route('surface') !== self::MODERN) {
            return $canonicalName;
        }

        $feature = strstr($canonicalName, '.', true);
        if ($feature === false) {
            return $canonicalName;
        }

        return $feature.'.modern.'.substr($canonicalName, strlen($feature) + 1);
    }
}
