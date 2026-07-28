<?php

namespace App\Support;

use App\Models\Member;
use App\Services\SnsSettingService;
use Illuminate\Http\Request;

/**
 * Decides whether a canonical feature route renders the Classic or Modern surface. URLs carry no
 * surface — the surface is an attribute of the viewer (install mode, durable member choice) and of
 * the client (an Inertia navigation is always Modern). Shared by every feature controller that
 * serves both surfaces.
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

        // An Inertia navigation can only originate from the Modern SPA, and answering it with
        // Classic Blade would make the client reject the response — so a Modern session sticks
        // across canonical URLs regardless of the viewer's resolved surface. A deliberate handoff
        // to Classic (the surface picker) bypasses this via Inertia::location (full page load).
        if ($request->hasHeader('X-Inertia')) {
            return self::MODERN;
        }

        return self::canonicalSurface($request, $feature);
    }

    /**
     * The surface the VIEWER resolves to — resolve() minus the Inertia-client stickiness. Honours
     * the hard gates (a non-native feature is Classic, modern_only is Modern) before the member's
     * durable choice / the mode's default surface. The member config page uses this both for the
     * surface it preselects and for its "saving the current surface is a no-op" check, so the form
     * reflects what the member gets on a fresh page load — not the SPA session the page is in, and
     * not the bare default when a hard gate overrides it.
     */
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
