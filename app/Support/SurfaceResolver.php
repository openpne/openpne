<?php

namespace App\Support;

use App\Models\Member;
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

        // An explicit /m/* route opts into Modern, above everything except a non-native feature.
        if ($request->route('surface') === self::MODERN) {
            return self::MODERN;
        }

        return self::canonicalSurface($request, $feature);
    }

    /**
     * The surface a member gets on a CANONICAL route — resolve() minus the explicit /m/* opt-in.
     * Still honours the hard gates (a non-native feature is Classic, modern_only is Modern) before
     * the member's durable choice / session toggle / tenant default. The member config page uses
     * this both for the surface it preselects and for its "saving the current surface is a no-op"
     * check, so the form reflects what the member actually sees when browsing normally — not the /m
     * URL the page itself may be on, and not the bare tenant default when a hard gate overrides it.
     */
    public static function canonicalSurface(Request $request, string $feature): string
    {
        if (config("features.{$feature}.modern_status", 'native') !== 'native') {
            return self::CLASSIC;
        }

        if (config('openpne.tenant_mode', 'mixed') === 'modern_only') {
            return self::MODERN;
        }

        // A member's durable choice (member_preferences) outranks the transient session toggle and
        // the tenant default.
        $member = $request->user('member');
        if ($member instanceof Member && ($preferred = $member->preferredSurface()) !== null) {
            return $preferred->value;
        }

        $override = $request->session()->get('migration_ui_override');
        if (in_array($override, [self::CLASSIC, self::MODERN], true)) {
            return $override;
        }

        return config('openpne.tenant_default_surface', self::CLASSIC);
    }

    /**
     * Whether the Classic surface is rendered for anyone on this site. True unless the site is
     * modern_only AND no feature is pinned off Modern — mirroring resolve()'s precedence, where a
     * non-native feature renders Classic even under modern_only. Lets admin UIs hide Classic-only
     * settings exactly when no one can reach the Classic surface, not merely when modern_only is set.
     */
    public static function classicReachable(): bool
    {
        if (config('openpne.tenant_mode', 'mixed') !== 'modern_only') {
            return true;
        }

        foreach ((array) config('features', []) as $feature) {
            if (is_array($feature) && ($feature['modern_status'] ?? 'native') !== 'native') {
                return true;
            }
        }

        return false;
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

    /**
     * Inverse of the modern-route convention: `friend.modern.list` -> `friend.list`.
     * Canonical names (no `.modern.` infix) pass through unchanged. Lets a controller
     * key parity/body-id lookups by canonical name even when a `/m/*` route ran and
     * fell back to Classic.
     */
    public static function canonicalName(string $routeName): string
    {
        return str_replace('.modern.', '.', $routeName);
    }
}
