<?php

declare(strict_types=1);

namespace App\Listeners\Http;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Symfony\Component\HttpFoundation\Response;

/**
 * Under mod_php the client only sees the end of a response that declares its length, so deferred
 * work after it would otherwise keep the client waiting (docs/internals/runtime.md, "Queue").
 * A listener registered after Livewire's, which rewrites the body in RequestHandled after every middleware.
 */
final class DeclareContentLength
{
    public function handle(RequestHandled $event): void
    {
        $response = $event->response;
        $content = $response->getContent();
        $headers = $response->headers;
        // A HEAD reply's length is its GET's, which the emptied body cannot give.
        if (! is_string($content)
            || $event->request->isMethod('HEAD')
            || $headers->has('Transfer-Encoding')
            || $response->isInformational()
            || in_array($response->getStatusCode(), [Response::HTTP_NO_CONTENT, Response::HTTP_NOT_MODIFIED], true)) {
            return;
        }

        $headers->set('Content-Length', (string) strlen($content));
    }
}
