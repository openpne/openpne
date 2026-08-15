<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Member;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The second belt on a frozen member: a ban deletes every personal access token in the same
 * transaction as the flag (App\Features\Member\Actions\RejectMemberLogin), so a token presented here
 * should already be gone. This refuses one that somehow is not — a token minted from a stale read, a
 * row restored from a backup — for the same reason the freeze ends sessions rather than trusting the
 * next login check.
 */
class EnsureTokenMemberNotFrozen
{
    public function handle(Request $request, Closure $next): Response
    {
        $member = $request->user();

        if ($member instanceof Member && $member->is_login_rejected) {
            // No redirect target: this realm answers 401 rather than sending a machine to a login form.
            throw new AuthenticationException;
        }

        return $next($request);
    }
}
