<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;

/**
 * Which realm (the member/admin split) a request belongs to, by path. One predicate for every
 * caller: UseAdminSessionStore pins the session store and guard with it, and the Classic error
 * page excludes the admin realm with it — the panel keeps its own error rendering.
 */
final class AdminRealm
{
    public static function matches(Request $request): bool
    {
        // 'admin' mirrors AdminPanelProvider->path('admin'); the Livewire and Filament
        // prefixes come from the same resolvers those packages register routes with.
        // All three are pinned against the real routes by AdminSessionStoreTest.
        // Livewire endpoints belong to the admin realm: nothing outside app/Filament
        // renders Livewire (architecture-test enforced), and the Filament system routes
        // (export/import downloads) authenticate against the admin guard.
        $livewire = ltrim(EndpointResolver::prefix(), '/');
        $filament = (string) config('filament.system_route_prefix', 'filament');

        return $request->is(
            'admin', 'admin/*',
            $livewire, $livewire.'/*',
            $filament, $filament.'/*',
        );
    }
}
