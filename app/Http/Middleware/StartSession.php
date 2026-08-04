<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\Middleware\StartSession as FrameworkStartSession;

/**
 * Records the session's previous URL only for a request that is a page the visitor is actually on.
 *
 * The framework records every routed, non-ajax GET, which includes the cookie-bearing subresources
 * a page pulls in — the brand mark on the sign-in screen, an avatar, a polling fetch. Whichever
 * loads last becomes the back-navigation target, so the redirect Laravel issues for a validation
 * error or a failed login lands on an image or a JSON endpoint instead of the form: the visitor
 * loses the error message, and the Inertia client is handed a response it cannot render.
 *
 * Fetch Metadata (`Sec-Fetch-Dest`) is what separates a navigation from a subresource. A client
 * that does not send it keeps the framework's rule, so no client loses back-navigation it had.
 */
class StartSession extends FrameworkStartSession
{
    protected function storeCurrentUrl(Request $request, $session)
    {
        $route = $request->route();

        // A fallback match is an unmatched URL — a stale link, a probe — answered with a 404. Never
        // somewhere to send a visitor back to, whatever headers it arrived with.
        if ($route instanceof Route && $route->isFallback) {
            return;
        }

        if (! $this->isPageNavigation($request)) {
            return;
        }

        parent::storeCurrentUrl($request, $session);
    }

    private function isPageNavigation(Request $request): bool
    {
        return match ($request->headers->get('Sec-Fetch-Dest')) {
            null => true,        // predates Fetch Metadata: the framework's rule stands
            'document' => true,  // ordinary navigation
            // A client-side visit swaps the page itself, so its URL is where the visitor now is.
            'empty' => $request->hasHeader('X-Inertia') || $request->hasHeader('X-Livewire-Navigate'),
            default => false,    // image, style, script, font, manifest, iframe, …
        };
    }
}
