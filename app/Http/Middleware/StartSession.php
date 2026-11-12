<?php

namespace App\Http\Middleware;

use App\Session\MemberSessionLifetime;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\Middleware\StartSession as FrameworkStartSession;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records the previous URL only for a page the visitor is on: a navigation request (Fetch Metadata,
 * or the framework's XHR rule without it) answered by a successful HTML or Inertia page
 * (docs/internals/sessions.md). The framework alone records cookie-bearing subresources and drops
 * client-side visits, so redirect()->back() would land on an image or skip a page.
 */
class StartSession extends FrameworkStartSession
{
    private const RESPONSE = 'app.start_session.response';

    private ?Request $request = null;

    /**
     * The framework calls storeCurrentUrl once the response exists but does not hand it over; it is
     * kept on the request so the store can ask what was answered.
     */
    protected function handleStatefulRequest(Request $request, $session, Closure $next)
    {
        $this->request = $request;

        try {
            return parent::handleStatefulRequest($request, $session, function (Request $request) use ($next) {
                $response = $next($request);
                $request->attributes->set(self::RESPONSE, $response);

                return $response;
            });
        } finally {
            $this->request = null;
        }
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

    /** A signed-in member's cookie outlives the framework's (docs/internals/sessions.md, "Lifetimes"). */
    protected function getCookieExpirationDate()
    {
        $minutes = $this->request === null ? null : MemberSessionLifetime::cookieMinutes($this->request);

        return $minutes === null ? parent::getCookieExpirationDate() : Date::instance(now()->addMinutes($minutes));
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
