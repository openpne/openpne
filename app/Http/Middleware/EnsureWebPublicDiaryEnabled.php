<?php

namespace App\Http\Middleware;

use App\Features\Diary\DiaryVisibility;
use App\Support\GuestLoginRedirect;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * With web-public diaries off (`SnsSettingKey::DiaryAllowWebPublic`, OpenPNE 3
 * `op_diary_plugin_use_open_diary`) OpenPNE 3 made the whole diary module members-only, so a guest is
 * bounced to login rather than shown an empty list.
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
