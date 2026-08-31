<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\Middleware\StartSession as FrameworkStartSession;
use Symfony\Component\HttpFoundation\Response;

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
 * Two things have to hold. The request has to be a navigation: Fetch Metadata (`Sec-Fetch-Dest`)
 * says so, and a client that does not send it keeps the framework's rule. And the response has to
 * be a page — a successful one that is HTML, or an Inertia page response. The second is what a
 * client without Fetch Metadata cannot get wrong: an image, a stylesheet, the manifest or a JSON
 * poll is not a page whatever headers asked for it, and neither is an error page — a 404 for a
 * stale icon URL renders as HTML on the Modern surface, and is no more somewhere to send a visitor
 * back to than the unmatched URL the framework answers the same way.
 */
class StartSession extends FrameworkStartSession
{
    private const RESPONSE = 'app.start_session.response';

    /**
     * The framework calls storeCurrentUrl once the response exists but does not hand it over; it is
     * kept on the request so the store can ask what was answered.
     */
    protected function handleStatefulRequest(Request $request, $session, Closure $next)
    {
        return parent::handleStatefulRequest($request, $session, function (Request $request) use ($next) {
            $response = $next($request);
            $request->attributes->set(self::RESPONSE, $response);

            return $response;
        });
    }

    protected function storeCurrentUrl(Request $request, $session)
    {
        $route = $request->route();
        $response = $request->attributes->get(self::RESPONSE);

        // The framework's own guards, minus the XHR one that isPageNavigation now decides.
        if (! $request->isMethod('GET')
            || ! $route instanceof Route
            || $request->prefetch()
            || $request->isPrecognitive()
            || ! $this->isPageNavigation($request)
            || ! $response instanceof Response
            || ! $this->isPage($response)) {
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

    /**
     * A successful answer that is HTML, or the JSON an Inertia client-side visit swaps the page for.
     * Status first: an error page is HTML too, on the surface that renders one, and the framework's
     * fallback 404 for an unmatched URL is the same case.
     */
    private function isPage(Response $response): bool
    {
        return $response->isSuccessful()
            && ($response->headers->has('X-Inertia')
                || str_starts_with((string) $response->headers->get('Content-Type'), 'text/html'));
    }
}
