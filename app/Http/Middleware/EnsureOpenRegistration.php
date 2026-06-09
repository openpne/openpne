<?php

namespace App\Http\Middleware;

use App\Features\Auth\RegistrationMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the open self-registration entry: 404 unless the registration mode is 'open', matching
 * OpenPNE 3, which 404'd requestRegisterURL when invite_mode was below open.
 */
class EnsureOpenRegistration
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(RegistrationMode::current()->allowsOpenRegistration(), 404);

        return $next($request);
    }
}
