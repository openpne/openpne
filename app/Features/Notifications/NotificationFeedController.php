<?php

namespace App\Features\Notifications;

use App\Features\Notifications\Queries\CountUnreadNotifications;
use App\Features\Notifications\Serializers\NotificationFeedSerializer;
use App\Http\Controllers\Concerns\RespondsWithSurface;
use App\Http\Controllers\Controller;
use App\Support\SurfaceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;
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
    use RespondsWithSurface;

    private const PAGE = 30;

    public function index(Request $request, CountUnreadNotifications $unread): View|Response
    {
        $viewer = $this->viewer();
        $rows = $viewer->notifications()->paginate(self::PAGE);

        return $this->respondWith($request, 'notifications', [
            // Modern shares the unread count on every page; Classic has no such shell prop, so the
            // mark-all button asks for it here (and hides on zero, as Modern's does).
            SurfaceResolver::CLASSIC => fn (): View => view('notifications.index', [
                'feed' => NotificationFeedSerializer::classicRows($rows),
                'unreadCount' => $unread($viewer),
            ]),
            SurfaceResolver::MODERN => fn (): Response => Inertia::render('notifications/index', [
                'feed' => NotificationFeedSerializer::paginator($rows),
            ]),
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
