<?php

namespace App\Features\GroupEvent;

use App\Compat\RouteParityRegistry;
use App\Features\GroupEvent\Actions\DeleteEventComment;
use App\Features\GroupEvent\Actions\SubmitEventComment;
use App\Features\GroupEvent\Exceptions\GroupEventActionException;
use App\Features\GroupEvent\Exceptions\GroupEventActionFailure;
use App\Http\Controllers\Controller;
use App\Http\Requests\GroupEvent\StoreEventCommentRequest;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Support\SurfaceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Event comments and the merged RSVP form, dual-surface for the write path. OpenPNE 3 posts
 * participation through the same comment-create endpoint: the participate/cancel buttons toggle the
 * roster and then save the (required) comment, while the "comment only" button just saves it.
 * SubmitEventComment runs the toggle, the comment and its images in one compensating transaction, so
 * a closed/expired/full guard aborts the whole submission. A guard failure is an in-app error (flash
 * + back), not a 404; the 404s are reserved for the membership gate. showDelete stays a Classic-only
 * GET confirm page — Modern confirms delete inline.
 */
class GroupEventCommentController extends Controller
{
    public function store(StoreEventCommentRequest $request, int $event, SubmitEventComment $submit): RedirectResponse
    {
        $found = GroupEvent::findOrFail($event);
        $body = $request->validated('body');
        $images = $request->file('images', []);

        // OpenPNE 3 toggles the roster unless the "comment only" button (name=comment) was pressed.
        $toggleRoster = ! $request->filled('comment');

        try {
            $joined = $submit($this->viewer(), $found, $body, $images, $toggleRoster);
        } catch (GroupEventActionException $e) {
            // A roster guard (closed / expired / full) is shown in place; the comment is rolled back.
            if ($this->isRosterGuard($e->reason)) {
                return $this->redirectToEvent($request, $found)->with('error', $this->rosterError($e->reason));
            }
            abort(404); // membership gate (defensive; the request already enforces it)
        }

        return $this->redirectToEvent($request, $found)->with('status', $this->postedMessage($joined));
    }

    public function showDelete(Request $request, GroupEventComment $comment): View|RedirectResponse
    {
        abort_unless(GroupEventAccess::canDeleteComment($comment, $this->viewer()), 404);

        // Modern confirms deletion inline — send a Modern viewer back to the event.
        if (SurfaceResolver::resolve($request, 'group') === SurfaceResolver::MODERN) {
            return redirect()->route('group.events.show', $comment->event);
        }

        // The confirm keeps the community context its event pages carry.
        $this->markLocalNavGroup($comment->event->group);

        return view('group-event.comment-delete', [
            'comment' => $comment,
            'pageId' => RouteParityRegistry::bodyId('group.events.comment.delete.show'),
        ]);
    }

    public function delete(Request $request, GroupEventComment $comment, DeleteEventComment $action): RedirectResponse
    {
        $event = $comment->event;

        try {
            $action($this->viewer(), $comment);
        } catch (GroupEventActionException) {
            abort(404);
        }

        return $this->redirectToEvent($request, $event)->with('status', __('The comment was deleted.'));
    }

    /** Redirect to the event show page on the surface the request came from (both key off {event}). */
    private function redirectToEvent(Request $request, GroupEvent $event): RedirectResponse
    {
        return redirect()->route('group.events.show', $event);
    }

    private function isRosterGuard(GroupEventActionFailure $reason): bool
    {
        return in_array($reason, [
            GroupEventActionFailure::EventClosed,
            GroupEventActionFailure::EventExpired,
            GroupEventActionFailure::EventAtCapacity,
        ], true);
    }

    private function rosterError(GroupEventActionFailure $reason): string
    {
        return match ($reason) {
            GroupEventActionFailure::EventClosed => __('This event has closed.'),
            GroupEventActionFailure::EventExpired => __('The application deadline has passed.'),
            GroupEventActionFailure::EventAtCapacity => __('This event is full.'),
            default => __('Comment posted.'),
        };
    }

    /** A toggle reports joined/left; a comment-only post ($joined === null) just confirms the comment. */
    private function postedMessage(?bool $joined): string
    {
        return match ($joined) {
            true => __('You joined the event.'),
            false => __('You left the event.'),
            null => __('Comment posted.'),
        };
    }
}
