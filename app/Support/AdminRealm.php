<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;

/** Which realm (member or admin) a request belongs to, by path: the one predicate, so no two realm-dependent behaviors disagree. */
final class AdminRealm
{
    public static function matches(Request $request): bool
    {
        // Livewire endpoints are admin-realm because nothing outside app/Filament renders Livewire,
        // and the Filament system routes serve the panel's exports and imports.
        $livewire = ltrim(EndpointResolver::prefix(), '/');
        $filament = (string) config('filament.system_route_prefix', 'filament');

        return $request->is(
            'admin', 'admin/*',
            $livewire, $livewire.'/*',
            $filament, $filament.'/*',
        );
    }

    /** False for a URL off this site's root, the host and an install's subpath included. */
    public static function matchesUrl(string $url): bool
    {
        $root = url('/').'/';

        return str_starts_with($url, $root) && self::matches(Request::create('/'.substr($url, strlen($root))));
    }
}
