<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;

/**
 * Which realm (member or admin) a request belongs to, by path: the one predicate, so the session
 * store, the guard and the error rendering cannot disagree about it.
 */
final class AdminRealm
{
    public static function matches(Request $request): bool
    {
        // Livewire endpoints are admin-realm because nothing outside app/Filament renders Livewire,
        // and the Filament system routes authenticate against the admin guard.
        $livewire = ltrim(EndpointResolver::prefix(), '/');
        $filament = (string) config('filament.system_route_prefix', 'filament');

        return $request->is(
            'admin', 'admin/*',
            $livewire, $livewire.'/*',
            $filament, $filament.'/*',
        );
    }
}
