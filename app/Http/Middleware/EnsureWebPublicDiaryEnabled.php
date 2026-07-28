<?php

namespace App\Http\Middleware;

use App\Features\Diary\DiaryVisibility;
use App\Support\GuestLoginRedirect;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the guest-reachable half of the diary module while web-public diaries are switched off
 * (`openpne.diary.allow_web_public`, OpenPNE 3 `op_diary_plugin_use_open_diary`). OpenPNE 3 made
 * the whole module members-only in that case (opDiaryPluginActions::initialize drops the per-action
 * `is_secure: false`), so the screens bounce to login rather than render an empty list.
 *
 * A signed-in member is unaffected: the switch only ever governed the web-public audience.
 */
class EnsureWebPublicDiaryEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null && ! DiaryVisibility::allowsWebPublic()) {
            return GuestLoginRedirect::response();
        }

        return $next($request);
    }
}
