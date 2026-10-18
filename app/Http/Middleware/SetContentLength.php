<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Under mod_php the client only sees the end of a response that declares its length; without it,
 * work the app does after the response (deferred jobs) keeps the client waiting (docs/internals/runtime.md, "Queue").
 */
final class SetContentLength
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $content = $response->getContent();
        $headers = $response->headers;
        if (! is_string($content)
            || $headers->has('Content-Length')
            || $headers->has('Transfer-Encoding')
            || $response->isInformational()
            || in_array($response->getStatusCode(), [Response::HTTP_NO_CONTENT, Response::HTTP_NOT_MODIFIED], true)) {
            return $response;
        }

        $headers->set('Content-Length', (string) strlen($content));

        return $response;
    }
}
