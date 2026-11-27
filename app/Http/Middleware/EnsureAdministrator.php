<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\AdminEntry;
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

        // The admin realm's only auth.session: a session whose password hash is stale is signed out
        // before the role is read.
        return $this->session->handle($request, function (Request $request) use ($member, $next): Response {
            abort_unless($member instanceof Member && $member->canAccessPanel(Filament::getPanel('admin')), 403);

            if (! AdminEntry::admit($request->session(), $member)) {
                return $this->toEntry($request);
            }

            return $next($request);
        });
    }

    /**
     * Only a GET page visit or a Livewire update is redirected, since Livewire follows a redirect with
     * the whole window and answers a refused upload with an update (docs/internals/administrators.md, "The panel's entry").
     */
    private function toEntry(Request $request): Response
    {
        $visit = $request->isMethod('GET') && StartSession::isPageNavigation($request);
        abort_unless($visit || $request->hasHeader('X-Livewire'), 403);

        $back = $visit ? $request->fullUrl() : $request->session()->previousUrl();
        redirect()->setIntendedUrl(is_string($back) && AdminRealm::matchesUrl($back) ? $back : Filament::getPanel('admin')->getUrl());

        return redirect()->route('member.admin_entry');
    }
}
