<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\Middleware\StartSession as FrameworkStartSession;

/**
 * Records the session's previous URL only for a request that is a page the visitor is actually on.
 *
 * The framework records every routed GET that is not an XHR, which gets both halves wrong here. It
 * records the cookie-bearing subresources a page pulls in — the brand mark on the sign-in screen, an
 * avatar, a polling fetch — so whichever loads last becomes the back-navigation target, and the
 * redirect issued for a validation error or a failed login lands on an image instead of the form.
 * And it drops client-side page visits, which are XHRs that *are* navigations, so on those pages
 * back() reaches for whatever full page load came before.
 *
 * Fetch Metadata (`Sec-Fetch-Dest`) is what separates the two. A client that does not send it keeps
 * the framework's rule, so no client loses back-navigation it had.
 */
class StartSession extends FrameworkStartSession
{
    protected function storeCurrentUrl(Request $request, $session)
    {
        $route = $request->route();

        // The framework's own guards, minus the XHR one that isPageNavigation now decides. A
        // fallback match is an unmatched URL — a stale link, a probe — answered with a 404, so it is
        // never somewhere to send a visitor back to, whatever headers it arrived with.
        if (! $request->isMethod('GET')
            || ! $route instanceof Route
            || $route->isFallback
            || $request->prefetch()
            || $request->isPrecognitive()
            || ! $this->isPageNavigation($request)) {
            return;
        }

        $session->setPreviousUrl($request->fullUrl());

        if (method_exists($session, 'setPreviousRoute')) {
            $session->setPreviousRoute($route->getName());
        }
    }

    private function isPageNavigation(Request $request): bool
    {
        return match ($request->headers->get('Sec-Fetch-Dest')) {
            null => ! $request->ajax(),  // predates Fetch Metadata: the framework's rule stands
            'document' => true,          // ordinary navigation
            // A client-side visit swaps the page itself, so its URL is where the visitor now is —
            // even though it is an XHR (Inertia's client sends X-Requested-With with X-Inertia).
            'empty' => $request->hasHeader('X-Inertia') || $request->hasHeader('X-Livewire-Navigate'),
            default => false,            // image, style, script, font, manifest, iframe, …
        };
    }
}
