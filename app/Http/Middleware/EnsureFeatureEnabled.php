<?php

namespace App\Http\Middleware;

use App\Support\Feature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a feature unit's routes: 404 while the unit — or the unit it lives inside — is switched
 * off, as OpenPNE 3 answered a disabled plugin's URLs. The routes stay registered (the parity
 * audits assert every mapped route exists), so the gate is this middleware, not the route table.
 *
 * The answer is the same for a guest and for a member, so the toggle state never leaks a
 * difference; inside an `auth` group a guest still meets the login redirect first.
 */
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless(Feature::from($feature)->enabled(), 404);

        return $next($request);
    }
}
