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
 * OpenPNE 3 posts participation through the same comment-create endpoint: the participate/cancel
 * buttons toggle the roster and then save the required comment, while "comment only" just saves it.
 * A roster guard failure is an in-app error, not a 404; the 404s are the membership gate's.
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

        if (SurfaceResolver::resolve($request, 'group') === SurfaceResolver::MODERN) {
            return redirect()->route('group.events.show', $comment->event);
        }

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

    /** Both surfaces key off {event}, so $request selects nothing here. */
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
