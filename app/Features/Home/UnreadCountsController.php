<?php

namespace App\Features\Home;

use App\Features\GroupTalk\Queries\NavTalkRooms;
use App\Http\Controllers\Controller;
use App\Support\Feature;
use Illuminate\Http\JsonResponse;

/**
 * The shell's live state on its own, for the visible-tab refresh that keeps it current between
 * navigations (resources/js/components/unread-sync.tsx). An endpoint of its own rather than an
 * Inertia partial reload: a partial reload still runs the current page's controller in full before
 * the props are filtered, so the cheapest page would pay for its whole payload every minute.
 *
 * The badge counts and the sidebar's room list travel together because the sidebar draws both, one
 * above the other: the groups badge counts rooms with something new, and the rows under it are those
 * rooms. Answering them from one read is what stops a refresh zeroing the badge while a row below it
 * still claims five unread.
 */
class UnreadCountsController extends Controller
{
    public function show(UnreadCounts $unread, NavTalkRooms $rooms): JsonResponse
    {
        $viewer = $this->viewer();

        return response()->json([
            'unread' => $unread->for($viewer),
            // Null, unqueried, while talk is off — the same answer the shared prop gives, so a
            // refresh cannot put a room list on a surface that never renders one.
            'talkNavRooms' => Feature::GroupTalk->enabled() ? $rooms($viewer) : null,
        ]);
    }
}
