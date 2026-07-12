<?php

namespace App\Features\Notifications;

use App\Features\Notifications\Serializers\NotificationFeedSerializer;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The per-event notification feed (layer 3): the standard Laravel `notifications` rows the
 * database channel writes, read newest-first with the row's own read_at as the read state.
 * Opening the feed does not mark anything read — opening a row does (plus an explicit
 * mark-all-read), so the unread badge only drops on the member's own action.
 */
class NotificationFeedController extends Controller
{
    private const PAGE = 30;

    public function index(): Response
    {
        return Inertia::render('notifications/index', [
            'feed' => NotificationFeedSerializer::paginator(
                $this->viewer()->notifications()->paginate(self::PAGE),
            ),
        ]);
    }

    /** Mark one row read and land on what it is about (or back on the feed when that is gone). */
    public function open(string $notification): RedirectResponse
    {
        /** @var DatabaseNotification $row */
        $row = $this->viewer()->notifications()->whereKey($notification)->firstOrFail();
        $row->markAsRead();

        return redirect(NotificationFeedSerializer::targetUrl($row) ?? route('notifications.index'));
    }

    public function readAll(): RedirectResponse
    {
        $this->viewer()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
