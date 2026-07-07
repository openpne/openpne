<?php

namespace App\Features\Notifications;

use App\Features\Notifications\Serializers\NotificationFeedSerializer;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The per-event notification feed (layer 3): the standard Laravel `notifications` rows the
 * database channel writes, read newest-first with the row's own read_at as the read state.
 * Opening the feed does not mark anything read — opening a row does (plus an explicit
 * mark-all-read), so the unread badge only drops on the member's own action.
 */
class NotificationFeedController
{
    private const PAGE = 30;

    public function index(Request $request): Response
    {
        return Inertia::render('notifications/index', [
            'feed' => NotificationFeedSerializer::paginator(
                $this->viewer($request)->notifications()->paginate(self::PAGE),
            ),
        ]);
    }

    /** Mark one row read and land on what it is about (or back on the feed when that is gone). */
    public function open(Request $request, string $notification): RedirectResponse
    {
        /** @var DatabaseNotification $row */
        $row = $this->viewer($request)->notifications()->whereKey($notification)->firstOrFail();
        $row->markAsRead();

        return redirect(NotificationFeedSerializer::targetUrl($row) ?? route('notifications.index'));
    }

    public function readAll(Request $request): RedirectResponse
    {
        $this->viewer($request)->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }

    private function viewer(Request $request): Member
    {
        /** @var Member $viewer */
        $viewer = $request->user();

        return $viewer;
    }
}
