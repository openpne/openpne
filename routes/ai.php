<?php

use App\Auth\PassportKeys;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureTokenMemberNotFrozen;
use App\Http\Middleware\ThrottleMcpByIp;
use App\Mcp\McpAbilities;
use App\Mcp\OAuth\RegisterController;
use App\Mcp\OAuth\TokenController;
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
    // Sanctum first: a personal token never builds the OAuth guard, which needs the signing keys.
    'auth:sanctum,oauth',
    EnsureTokenMemberNotFrozen::class,
    'ability:'.McpAbilities::READ,
    EnsureFeatureEnabled::class.':mcp',
    'throttle:mcp',
])->group(function (): void {
    Mcp::web('/mcp', OpenPneServer::class);
});

// Registered only with the signing keys, under Passport's route names, which the metadata links to
// (docs/internals/mcp.md, "Signing in from a client").
if (PassportKeys::present()) {
    Route::middleware(EnsureFeatureEnabled::class.':mcp')->group(function (): void {
        Mcp::oauthRoutes(registration: false);
    });

    Route::post('/oauth/token', [TokenController::class, 'issueToken'])
        ->middleware([ThrottleMcpByIp::class.':oauth_token', EnsureFeatureEnabled::class.':mcp'])
        ->name('passport.token');
    Route::post('/oauth/register', RegisterController::class)
        ->middleware([ThrottleMcpByIp::class.':oauth_register', EnsureFeatureEnabled::class.':mcp'])
        ->name('mcp.oauth.register');
}
