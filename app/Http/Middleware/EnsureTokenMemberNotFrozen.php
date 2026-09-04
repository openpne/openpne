<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Features\AiAccount\TokenActorEligibility;
use App\Models\Member;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A ban deletes every token in the same transaction as the flag, so this refuses only a token that
 * sweep did not reach (a stale read, a row restored from a backup). "Frozen" reaches the owner: an AI
 * account's token also ends when its owning member is banned, so the question goes through
 * TokenActorEligibility rather than the caller's own flag.
 */
class EnsureTokenMemberNotFrozen
{
    public function handle(Request $request, Closure $next): Response
    {
        $member = $request->user();

        if ($member instanceof Member && ! TokenActorEligibility::permits($member)) {
            // No redirect target: this realm answers 401 rather than sending a machine to a login form.
            throw new AuthenticationException;
        }

        return $next($request);
    }
}
