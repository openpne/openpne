<?php

namespace App\Http\Middleware;

use App\Features\Auth\RegistrationMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the member invitation entry: 404 unless the registration mode allows member invites
 * (open/invite), matching OpenPNE 3, which served member/invite only when invite_mode permitted it.
 */
class EnsureMemberInviteAllowed
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(RegistrationMode::current()->allowsMemberInvite(), 404);

        return $next($request);
    }
}
