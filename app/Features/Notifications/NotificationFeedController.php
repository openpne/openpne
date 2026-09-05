<?php

namespace App\Features\Notifications;

use App\Features\Notifications\Queries\CountUnreadNotifications;
use App\Features\Notifications\Queries\VisibleNotifications;
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
 * Opening the feed marks nothing read; opening a row does, as does an explicit mark-all-read
 * (docs/internals/notifications.md, The three layers).
 */
class NotificationFeedController extends Controller
{
    use RespondsWithSurface;

    private const PAGE = 30;

    public function index(Request $request, CountUnreadNotifications $unread): View|Response
    {
        $viewer = $this->viewer();
        $rows = VisibleNotifications::apply($viewer->notifications())->paginate(self::PAGE);

        return $this->respondWith($request, 'notifications', [
            // Counted over the whole feed, not the header center's window: this page pages past it.
            SurfaceResolver::CLASSIC => fn (): View => view('notifications.index', [
                'feed' => NotificationFeedSerializer::classicRows($rows),
                'unreadCount' => $unread($viewer),
            ]),
            SurfaceResolver::MODERN => fn (): Response => Inertia::render('notifications/index', [
                'feed' => NotificationFeedSerializer::paginator($rows),
            ]),
        ]);
    }

    public function open(string $notification): RedirectResponse
    {
        /** @var ?DatabaseNotification $row */
        $row = $this->viewer()->notifications()->whereKey($notification)->first();

        // A missing row returns to the feed rather than erroring; only a row that exists but belongs to
        // a switched-off unit is refused (docs/internals/notifications.md, The three layers).
        if ($row === null) {
            return redirect()->route('notifications.index');
        }
        abort_if(VisibleNotifications::hides($row), 404);

        $row->markAsRead();

        return redirect(NotificationFeedSerializer::targetUrl($row) ?? route('notifications.index'));
    }

    public function readAll(): RedirectResponse
    {
        VisibleNotifications::apply($this->viewer()->unreadNotifications())->update(['read_at' => now()]);

        return back();
    }
}
