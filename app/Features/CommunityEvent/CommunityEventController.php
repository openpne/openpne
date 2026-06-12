<?php

namespace App\Features\CommunityEvent;

use App\Compat\RouteParityRegistry;
use App\Features\CommunityEvent\Actions\CreateEvent;
use App\Features\CommunityEvent\Actions\DeleteEvent;
use App\Features\CommunityEvent\Actions\UpdateEvent;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionException;
use App\Features\CommunityEvent\Queries\EventParticipants;
use App\Features\CommunityEvent\Queries\ListCommunityEvents;
use App\Features\CommunityEvent\Queries\ShowEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\CommunityEvent\StoreEventRequest;
use App\Http\Requests\CommunityEvent\UpdateEventRequest;
use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Classic-only adapter for community events (Modern is none, like the topic board). The board-level
 * gates (view a community's events, post one) key on Community, so they call CommunityEventAccess
 * directly; the event-level gates (edit/delete) go through the auto-discovered CommunityEventPolicy
 * via Gate. Every failure is a 404, hiding the event's existence.
 */
class CommunityEventController extends Controller
{
    public function index(Request $request, Community $community, ListCommunityEvents $query): View
    {
        $viewer = $this->viewer();
        abort_unless(CommunityEventAccess::canViewBoard($community, $viewer), 404);

        return $this->classic('community-event.index', [
            'community' => $community,
            'events' => $query($community),
            'canPost' => CommunityEventAccess::canPostEvent($community, $viewer),
        ]);
    }

    public function show(Request $request, int $event, ShowEvent $query): View
    {
        $found = $query($event);
        abort_if($found === null, 404);
        $viewer = $this->viewer();
        abort_unless(CommunityEventAccess::canViewEvent($found, $viewer), 404);

        return $this->classic('community-event.show', [
            'event' => $found,
            'thread' => CommunityEventCommentThread::paginate($found, $request->query('order'), $request->query('page')),
            'canComment' => CommunityEventAccess::canComment($found, $viewer),
            'canEdit' => CommunityEventAccess::canEditEvent($found, $viewer),
            // The merged RSVP/comment form mirrors OpenPNE 3: the participate/cancel button shows
            // only while the roster is open, keyed on the viewer's current membership and capacity.
            'isParticipant' => $found->isParticipant($viewer),
            'isClosed' => $found->isClosed(),
            'isExpired' => $found->isExpired(),
            'isFull' => $found->isFull(),
        ]);
    }

    public function new(Request $request, Community $community): View
    {
        abort_unless(CommunityEventAccess::canPostEvent($community, $this->viewer()), 404);

        return $this->classic('community-event.new', ['community' => $community]);
    }

    public function store(StoreEventRequest $request, Community $community, CreateEvent $action): RedirectResponse
    {
        try {
            $event = $action($this->viewer(), $community, $request->toData(), $request->file('images', []));
        } catch (CommunityEventActionException) {
            abort(404);
        }

        return redirect()->route('communityEvent.show', $event)->with('status', __('Event posted.'));
    }

    public function edit(Request $request, CommunityEvent $event): View
    {
        abort_unless(Gate::allows('update', $event), 404);
        $event->load('images.file');

        return $this->classic('community-event.edit', ['event' => $event]);
    }

    public function update(UpdateEventRequest $request, CommunityEvent $event, UpdateEvent $action): RedirectResponse
    {
        try {
            $action($this->viewer(), $event, $request->toData(), $request->file('images', []), $request->input('remove_images', []));
        } catch (CommunityEventActionException) {
            abort(404);
        }

        return redirect()->route('communityEvent.show', $event)->with('status', __('Event updated.'));
    }

    public function showDelete(Request $request, CommunityEvent $event): View
    {
        abort_unless(Gate::allows('delete', $event), 404);

        return $this->classic('community-event.delete', ['event' => $event]);
    }

    public function delete(Request $request, CommunityEvent $event, DeleteEvent $action): RedirectResponse
    {
        $community = $event->community;

        try {
            $action($this->viewer(), $event);
        } catch (CommunityEventActionException) {
            abort(404);
        }

        // OpenPNE 3 returns to the community home after deleting an event.
        return redirect()->route('community.show', $community)->with('status', __('Event deleted.'));
    }

    public function memberList(Request $request, CommunityEvent $event, EventParticipants $query): View
    {
        abort_unless(CommunityEventAccess::canViewEvent($event, $this->viewer()), 404);

        return $this->classic('community-event.member-list', [
            'event' => $event,
            'participants' => $query($event),
        ]);
    }

    /** Render a Classic view with the OpenPNE 3 page_{module}_{action} body id from the parity. */
    private function classic(string $view, array $data = []): View
    {
        // OpenPNE 3 sets the community localNav on every event action (sf_nav_type=community).
        $community = $data['community'] ?? ($data['event'] ?? null)?->community;
        if ($community instanceof Community) {
            $this->markLocalNavCommunity($community);
        }

        return view($view, $data)->with('pageId', RouteParityRegistry::bodyId($this->routeName()));
    }

    private function routeName(): string
    {
        $route = request()->route();

        return $route !== null ? (string) $route->getName() : '';
    }

    private function viewer(): Member
    {
        $viewer = auth()->user();
        assert($viewer instanceof Member);

        return $viewer;
    }
}
