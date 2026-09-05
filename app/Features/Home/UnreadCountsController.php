<?php

namespace App\Features\Home;

use App\Features\GroupTalk\Queries\NavTalkRooms;
use App\Http\Controllers\Controller;
use App\Support\Feature;
use Illuminate\Http\JsonResponse;

/**
 * The shell's live state on its own, for the visible-tab refresh: an endpoint rather than an Inertia
 * partial reload, which would still run the page's controller in full before filtering the props.
 * Counts and room list travel together, so a refresh cannot zero the badge while a row below it
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
