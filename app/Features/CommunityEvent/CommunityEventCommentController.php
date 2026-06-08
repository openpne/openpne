<?php

namespace App\Features\CommunityEvent;

use App\Compat\RouteParityRegistry;
use App\Features\CommunityEvent\Actions\CreateEventComment;
use App\Features\CommunityEvent\Actions\DeleteEventComment;
use App\Features\CommunityEvent\Actions\ToggleParticipation;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionException;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionFailure;
use App\Http\Controllers\Controller;
use App\Http\Requests\CommunityEvent\StoreEventCommentRequest;
use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Classic-only adapter for event comments and the merged RSVP form. OpenPNE 3 posts participation
 * through the same comment-create endpoint: the participate/cancel buttons toggle the roster and then
 * save the (required) comment, while the "comment only" button just saves it. The toggle runs before
 * the save inside one transaction, so a closed/expired/full guard aborts both — the comment is not
 * saved either, matching OpenPNE 3. A guard failure is an in-app error (flash + back), not a 404;
 * the 404s are reserved for the membership gate enforced in the request.
 */
class CommunityEventCommentController extends Controller
{
    public function store(
        StoreEventCommentRequest $request,
        int $event,
        ToggleParticipation $toggle,
        CreateEventComment $comment,
    ): RedirectResponse {
        $found = CommunityEvent::findOrFail($event);
        $viewer = $this->viewer();
        $body = $request->validated('body');
        $images = $request->file('images', []);

        // OpenPNE 3 toggles the roster unless the "comment only" button (name=comment) was pressed.
        $commentOnly = $request->filled('comment');

        try {
            $joined = DB::transaction(function () use ($commentOnly, $toggle, $comment, $viewer, $found, $body, $images): ?bool {
                $joined = $commentOnly ? null : $toggle($viewer, $found);
                $comment($viewer, $found, $body, $images);

                return $joined;
            });
        } catch (CommunityEventActionException $e) {
            // A roster guard (closed / expired / full) is shown in place; the comment is rolled back.
            if ($this->isRosterGuard($e->reason)) {
                return redirect()->route('communityEvent.show', $found)->with('error', $this->rosterError($e->reason));
            }
            abort(404); // membership gate (defensive; the request already enforces it)
        }

        return redirect()->route('communityEvent.show', $found)->with('status', $this->postedMessage($joined));
    }

    public function showDelete(Request $request, CommunityEventComment $comment): View
    {
        abort_unless(CommunityEventAccess::canDeleteComment($comment, $this->viewer()), 404);

        return view('community-event.comment-delete', [
            'comment' => $comment,
            'pageId' => RouteParityRegistry::bodyId('communityEvent.comment.delete.show'),
        ]);
    }

    public function delete(Request $request, CommunityEventComment $comment, DeleteEventComment $action): RedirectResponse
    {
        $event = $comment->event;

        try {
            $action($this->viewer(), $comment);
        } catch (CommunityEventActionException) {
            abort(404);
        }

        return redirect()->route('communityEvent.show', $event)->with('status', __('The comment was deleted.'));
    }

    private function isRosterGuard(CommunityEventActionFailure $reason): bool
    {
        return in_array($reason, [
            CommunityEventActionFailure::EventClosed,
            CommunityEventActionFailure::EventExpired,
            CommunityEventActionFailure::EventAtCapacity,
        ], true);
    }

    private function rosterError(CommunityEventActionFailure $reason): string
    {
        return match ($reason) {
            CommunityEventActionFailure::EventClosed => __('This event has closed.'),
            CommunityEventActionFailure::EventExpired => __('The application deadline has passed.'),
            CommunityEventActionFailure::EventAtCapacity => __('This event is full.'),
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

    private function viewer(): Member
    {
        $viewer = auth()->user();
        assert($viewer instanceof Member);

        return $viewer;
    }
}
