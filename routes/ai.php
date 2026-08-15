<?php

use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureTokenMemberNotFrozen;
use App\Http\Middleware\ThrottleMcpByIp;
use App\Mcp\McpAbilities;
use App\Mcp\Servers\OpenPneServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;

/*
 * The MCP endpoint — a stateless bearer-token realm, outside the `web` group and so without session,
 * CSRF, Inertia or the guest redirect. See docs/internals/mcp.md.
 *
 * The gate is declared on a GROUP rather than on Mcp::web()'s return value. Mcp::web registers three
 * routes on the path — the POST that carries JSON-RPC, plus a GET and a DELETE that answer 405 — and
 * returns only the POST, so middleware chained onto it would leave the other two open.
 */
Route::middleware([
    // The package attaches this to the POST, inside everything below, where a 401 thrown by the gate
    // never reaches it. Outermost here so the challenge header travels with every refusal, as the
    // transport spec asks. Listed twice on the POST and deduplicated by the router.
    AddWwwAuthenticateHeader::class,
    ThrottleMcpByIp::class,
    'auth:sanctum',
    EnsureTokenMemberNotFrozen::class,
    'ability:'.McpAbilities::READ,
    EnsureFeatureEnabled::class.':mcp',
    'throttle:mcp',
])->group(function (): void {
    Mcp::web('/mcp', OpenPneServer::class);
});
