<?php

declare(strict_types=1);

namespace App\Features\Notifications;

use App\Features\Friend\Actions\AcceptFriendRequest;
use App\Features\Friend\Actions\RejectFriendRequest;
use App\Features\Friend\Exceptions\FriendActionException;
use App\Features\Friend\Exceptions\FriendActionFailure;
use App\Features\Friend\FriendActionMessage;
use App\Features\Notifications\Queries\ListNotificationCenterRows;
use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The panel behind the Classic header sprite. OpenPNE 3 fetched its contents on the first open
 * rather than with every page, and this keeps that: a panel nobody opens costs one page nothing.
 *
 * The list answers in HTML because every sentence in it is already resolved in PHP
 * ([`NotificationKindLabel`](NotificationKindLabel.php)); re-deriving them in the browser would put
 * a second copy of the wording where the first one cannot check it. Only the two decisions answer
 * in JSON, which is state, not text.
 */
class NotificationCenterController extends Controller
{
    public function panel(ListNotificationCenterRows $rows): Response
    {
        return response()
            ->view('layouts.partials.notification-center-rows', ['rows' => $rows($this->viewer())])
            // Somebody else's notifications must never come out of a shared cache.
            ->header('Cache-Control', 'private, no-store');
    }

    public function acceptFriend(string $notification, AcceptFriendRequest $accept): JsonResponse
    {
        return $this->decide($notification, fn (Member $viewer, Member $requester) => $accept($viewer, $requester),
            __('%Friend% request accepted.'));
    }

    public function rejectFriend(string $notification, RejectFriendRequest $reject): JsonResponse
    {
        return $this->decide($notification, fn (Member $viewer, Member $requester) => $reject($viewer, $requester),
            __('%Friend% request rejected.'));
    }

    /**
     * Who the decision is about comes from the member's own notification row, never from the
     * request body: the row is the only thing here the member is proven to own.
     */
    private function decide(string $notification, callable $act, string $done): JsonResponse
    {
        $viewer = $this->viewer();

        /** @var DatabaseNotification $row */
        $row = $viewer->notifications()->whereKey($notification)->firstOrFail();

        if (($row->data['kind'] ?? null) !== 'friend_requested') {
            abort(404);
        }

        $requester = Member::find(ListNotificationCenterRows::requesterId($row));
        if ($requester === null) {
            return $this->resolved($row, __('This member is unavailable.'));
        }

        try {
            $act($viewer, $requester);
        } catch (FriendActionException $e) {
            // Deciding twice, or deciding what someone already withdrew, is a stale panel rather
            // than an error: the row is spent either way, so retire it and say so.
            return $e->reason === FriendActionFailure::RequestNotFound
                ? $this->resolved($row, __('This request has already been answered.'))
                : $this->resolved($row, FriendActionMessage::for($e->reason), ok: false);
        }

        return $this->resolved($row, $done);
    }

    private function resolved(DatabaseNotification $row, string $message, bool $ok = true): JsonResponse
    {
        $row->markAsRead();

        return response()->json(['ok' => $ok, 'message' => $message]);
    }
}
