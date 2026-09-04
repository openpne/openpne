<?php

use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureTokenMemberNotFrozen;
use App\Http\Middleware\ThrottleMcpByIp;
use App\Mcp\McpAbilities;
use App\Mcp\Servers\OpenPneServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;

// Mcp::web() registers three routes but returns only the POST, so the gate is on a group or the GET
// and DELETE would be left open (docs/internals/mcp.md).
Route::middleware([
    // Outermost so the challenge header travels with every refusal; the package's own copy sits inside
    // the gate, where a 401 never reaches it.
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
