<?php

namespace App\Http\Middleware;

use App\Support\FileDeliveryRoutes;
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
 * the framework's rule, so no client loses back-navigation it had — except on a route that never
 * answers with a page (isNeverAPage): those are ruled out by route, so the icon or image a
 * header-less client loads for a page cannot become the back target either.
 */
class StartSession extends FrameworkStartSession
{
    /**
     * Generated site assets a page's <head> pulls in. Not stored bytes, so not delivery routes —
     * whether their `public` declaration should also go cookie-free is a separate decision, and
     * listing them there would make it — but no more a page than those are.
     */
    public const ASSET_ROUTES = ['app_icon', 'webmanifest', 'design.customizing_css'];

    protected function storeCurrentUrl(Request $request, $session)
    {
        $route = $request->route();

        // The framework's own guards, minus the XHR one that isPageNavigation now decides.
        if (! $request->isMethod('GET')
            || ! $route instanceof Route
            || $this->isNeverAPage($route)
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

    /**
     * Nowhere to send a visitor back to, whatever headers the request carried: a fallback match is an
     * unmatched URL — a stale link, a probe — answered with a 404; a delivery route and a site asset
     * answer with bytes.
     */
    private function isNeverAPage(Route $route): bool
    {
        return $route->isFallback
            || FileDeliveryRoutes::matches($route)
            || in_array($route->getName(), self::ASSET_ROUTES, true);
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
