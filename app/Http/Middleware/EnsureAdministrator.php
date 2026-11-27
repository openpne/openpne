<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Member;
use App\Support\AdminRealm;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * In the `web` group and the panel's own stack, so it covers every admin-realm route whichever stack
 * serves it (docs/internals/administrators.md, "The admin panel").
 */
final class EnsureAdministrator
{
    /** Route names on the admin realm that need no administrator. */
    public const EXEMPT = ['filament.admin.auth.logout'];

    public function __construct(private readonly AuthenticateSession $session) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! AdminRealm::matches($request) || in_array($request->route()?->getName(), self::EXEMPT, true)) {
            return $next($request);
        }

        $member = Auth::guard('member')->user();
        if ($member === null) {
            throw new AuthenticationException('Unauthenticated.', ['member']);
        }

        // auth.session here too, since only the panel's own stack has it: a session whose password hash
        // is stale is signed out before the role is read.
        return $this->session->handle($request, function (Request $request) use ($member, $next): Response {
            abort_unless($member instanceof Member && $member->canAccessPanel(Filament::getPanel('admin')), 403);

            return $next($request);
        });
    }
}
