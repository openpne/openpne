<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * laravel/mcp decodes the raw body whatever its type, so a form-typed body the entry point lets through
 * is held to the same limit here, ahead of authentication (docs/internals/security.md, "Request body length").
 */
class RefuseLongMcpBody
{
    public function handle(Request $request, Closure $next): Response
    {
        require_once base_path('bootstrap/body-length-cap.php');

        if (openpne_body_exceeds($request->server->all(), $request->getContent(true))) {
            return response(OPENPNE_BODY_TOO_LARGE_BODY, 413, OPENPNE_BODY_TOO_LARGE_HEADERS);
        }

        return $next($request);
    }
}
