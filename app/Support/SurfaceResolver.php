<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Decides whether a canonical feature route renders the Classic or Modern
 * surface, and maps a canonical route name to its Modern sibling on redirect.
 * Shared by every feature controller that serves both surfaces (Friend, Block).
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
     * On a Modern route, maps `friend.list` -> `friend.modern.list` so a
     * post-submit redirect stays on the surface it came from. Consumers must
     * name routes `{feature}.{rest}` <-> `{feature}.modern.{rest}`.
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
