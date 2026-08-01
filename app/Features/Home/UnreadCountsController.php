<?php

namespace App\Features\Home;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * The shell's badge counts on their own, for the visible-tab refresh that keeps them live between
 * navigations (resources/js/components/unread-sync.tsx). An endpoint of its own rather than an
 * Inertia partial reload: a partial reload still runs the current page's controller in full before
 * the props are filtered, so the cheapest page would pay for its whole payload every minute.
 */
class UnreadCountsController extends Controller
{
    public function show(UnreadCounts $unread): JsonResponse
    {
        return response()->json($unread->for($this->viewer()));
    }
}
