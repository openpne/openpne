<?php

namespace App\Http\Middleware;

use App\Features\Timeline\TimelinePosting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** 404 while `SnsSettingKey::TimelinePostingEnabled` is off, as OpenPNE 3's forward404Unless answered its posting action. */
class EnsureTimelinePostingEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(TimelinePosting::enabled(), 404);

        return $next($request);
    }
}
