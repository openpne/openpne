<?php

namespace App\Support;

use App\Models\Member;
use App\Services\SnsSettingService;
use Illuminate\Http\Request;

/**
 * URLs carry no surface: it is an attribute of the viewer (install mode, durable member choice) and
 * of the client (an Inertia navigation is always Modern).
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

        // The Modern client rejects a Blade response, so an Inertia navigation stays Modern whatever the
        // viewer resolves to; a handoff to Classic is a full page load (Inertia::location).
        if ($request->hasHeader('X-Inertia')) {
            return self::MODERN;
        }

        return self::canonicalSurface($request, $feature);
    }

    /** resolve() minus the Inertia-client stickiness: what the viewer gets on a fresh page load. */
    public static function canonicalSurface(Request $request, string $feature): string
    {
        if (config("features.{$feature}.modern_status", 'native') !== 'native') {
            return self::CLASSIC;
        }

        return self::viewerSurface($request);
    }

    /**
     * The surface an error page renders in. An error is not a feature — the request may have
     * matched no route at all — so only the client and viewer gates apply.
     */
    public static function forError(Request $request): string
    {
        // Same reason as resolve(): the Modern client cannot consume Classic Blade.
        if ($request->hasHeader('X-Inertia')) {
            return self::MODERN;
        }

        return self::viewerSurface($request);
    }

    /**
     * The viewer's own surface, with no feature or client gate: modern_only, else the member's
     * durable choice (member_preferences), else the mode's default surface.
     */
    private static function viewerSurface(Request $request): string
    {
        $mode = self::surfaceMode();
        if (! $mode->classicAvailable()) {
            return self::MODERN;
        }

        $member = $request->user('member');
        if ($member instanceof Member && ($preferred = $member->preferredSurface()) !== null) {
            return $preferred->value;
        }

        return $mode->defaultSurface()->value;
    }

    /** Whether the Classic surface is served on this install (false only under modern_only). */
    public static function classicAvailable(): bool
    {
        return self::surfaceMode()->classicAvailable();
    }

    /** The install's surface mode — DB-authoritative (sns_settings), config as the absent-row fallback. */
    private static function surfaceMode(): SurfaceMode
    {
        return app(SnsSettingService::class)->get(SnsSettingKey::SurfaceMode);
    }
}
