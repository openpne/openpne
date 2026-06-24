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

        if ($request->route('surface') === self::MODERN) {
            return self::MODERN;
        }

        if (config('openpne.tenant_mode', 'mixed') === 'modern_only') {
            return self::MODERN;
        }

        // A member's durable choice (member_preferences) outranks the transient session toggle and
        // the tenant default — but never the explicit /m/* URL, modern_only, or a non-native feature.
        return self::preferenceOrDefault($request);
    }

    /**
     * The surface a member gets on a canonical route from their own choice alone: the durable
     * member_preferences value, else the session toggle, else the tenant default. Unlike resolve()
     * this ignores the request's /m/* route default, so the member config page (reachable on both
     * /member/config and /m/member/config) can show the member's actual current surface rather than
     * the surface of whichever URL they opened.
     */
    public static function preferenceOrDefault(Request $request): string
    {
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
