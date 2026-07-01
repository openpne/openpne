<?php

namespace App\Features\CommunityEvent;

use App\Compat\RouteParityRegistry;
use App\Features\Community\Serializers\CommunitySerializer;
use App\Features\CommunityEvent\Actions\CreateEvent;
use App\Features\CommunityEvent\Actions\DeleteEvent;
use App\Features\CommunityEvent\Actions\UpdateEvent;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionException;
use App\Features\CommunityEvent\Queries\EventParticipants;
use App\Features\CommunityEvent\Queries\ListCommunityEvents;
use App\Features\CommunityEvent\Queries\ShowEvent;
use App\Features\CommunityEvent\Serializers\CommunityEventSerializer;
use App\Http\Controllers\Concerns\RespondsWithSurface;
use App\Http\Controllers\Controller;
use App\Http\Requests\CommunityEvent\StoreEventRequest;
use App\Http\Requests\CommunityEvent\UpdateEventRequest;
use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\Member;
use App\Support\SurfaceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Community events, dual-surface: each action serves Classic Blade or Modern Inertia per
 * SurfaceResolver. Board-level gates (view a community's events, post one) key on Community, so they
 * call CommunityEventAccess directly; event-level gates (edit/delete) go through the auto-discovered
 * CommunityEventPolicy via Gate. Every failure is a 404. The Classic community localNav side effect
 * runs only in the Classic branch. showDelete stays a Classic-only GET confirm page — Modern confirms
 * inline. RSVP (join/cancel) is posted through the comment endpoint (CommunityEventCommentController).
 */
class CommunityEventController extends Controller
{
    use RespondsWithSurface;

    public function index(Request $request, Community $community, ListCommunityEvents $query): View|InertiaResponse
    {
        $viewer = $this->viewer();
        abort_unless(CommunityEventAccess::canViewBoard($community, $viewer), 404);
        $events = $query($community);
        $canPost = CommunityEventAccess::canPostEvent($community, $viewer);

        return $this->respondWith($request, 'community', [
            SurfaceResolver::CLASSIC => function () use ($community, $events, $canPost) {
                $this->markLocalNavCommunity($community);

                return view('community-event.index', [
                    'community' => $community,
                    'events' => $events,
                    'canPost' => $canPost,
                ]);
            },
            SurfaceResolver::MODERN => fn () => Inertia::render('community/event/index', [
                'community' => CommunitySerializer::summary($community),
                'events' => CommunityEventSerializer::paginator($events),
                'canPost' => $canPost,
            ]),
        ]);
    }

    public function show(Request $request, int $event, ShowEvent $query): View|InertiaResponse
    {
        $found = $query($event);
        abort_if($found === null, 404);
        $viewer = $this->viewer();
        abort_unless(CommunityEventAccess::canViewEvent($found, $viewer), 404);

        return $this->respondWith($request, 'community', [
            SurfaceResolver::CLASSIC => function () use ($request, $found, $viewer) {
                $this->markLocalNavCommunity($found->community);

                return view('community-event.show', [
                    'event' => $found,
                    'thread' => CommunityEventCommentThread::paginate($found, $request->query('order'), $request->query('page')),
                    'canComment' => CommunityEventAccess::canComment($found, $viewer),
                    'canEdit' => CommunityEventAccess::canEditEvent($found, $viewer),
                    'isParticipant' => $found->isParticipant($viewer),
                    'isClosed' => $found->isClosed(),
                    'isExpired' => $found->isExpired(),
                    'isFull' => $found->isFull(),
                ]);
            },
            SurfaceResolver::MODERN => function () use ($request, $found, $viewer) {
                $found->loadMissing('member.avatar.file');
                // Reuse the id-ordered, size-20 pager so Modern matches Classic and never serializes
                // an unbounded thread (same contract as the topic board).
                $thread = CommunityEventCommentThread::paginate($found, $request->query('order'), $request->query('page'));

                return Inertia::render('community/event/show', [
                    'community' => CommunitySerializer::summary($found->community),
                    'event' => CommunityEventSerializer::detail($found),
                    'thread' => CommunityEventSerializer::thread($thread, $viewer),
                    'canComment' => CommunityEventAccess::canComment($found, $viewer),
                    'canEdit' => CommunityEventAccess::canEditEvent($found, $viewer),
                    // RSVP button state: OpenPNE 3 shows participate/cancel only while the roster is
                    // open, keyed on the viewer's membership and the capacity/time guards.
                    'isParticipant' => $found->isParticipant($viewer),
                    'rosterOpen' => ! $found->isClosed() && ! $found->isExpired(),
                    'isFull' => $found->isFull(),
                ]);
            },
        ]);
    }

    public function new(Request $request, Community $community): View|InertiaResponse
    {
        abort_unless(CommunityEventAccess::canPostEvent($community, $this->viewer()), 404);

        return $this->respondWith($request, 'community', [
            SurfaceResolver::CLASSIC => function () use ($community) {
                $this->markLocalNavCommunity($community);

                return view('community-event.new', ['community' => $community]);
            },
            SurfaceResolver::MODERN => fn () => Inertia::render('community/event/edit', [
                'community' => CommunitySerializer::summary($community),
                'event' => null,
            ]),
        ]);
    }

    public function store(StoreEventRequest $request, Community $community, CreateEvent $action): RedirectResponse
    {
        try {
            $event = $action($this->viewer(), $community, $request->toData(), $request->file('images', []));
        } catch (CommunityEventActionException) {
            abort(404);
        }

        return $this->redirectToEvent($request, $event)->with('status', __('Event posted.'));
    }

    public function edit(Request $request, CommunityEvent $event): View|InertiaResponse
    {
        abort_unless(Gate::allows('update', $event), 404);
        $event->loadMissing('community', 'images.file');

        return $this->respondWith($request, 'community', [
            SurfaceResolver::CLASSIC => function () use ($event) {
                $this->markLocalNavCommunity($event->community);

                return view('community-event.edit', ['event' => $event]);
            },
            SurfaceResolver::MODERN => fn () => Inertia::render('community/event/edit', [
                'community' => CommunitySerializer::summary($event->community),
                // Form-shaped: the date widgets need Y-m-d, not the ISO datetime the detail carries.
                'event' => [
                    'id' => $event->getKey(),
                    'name' => $event->name,
                    'body' => $event->body,
                    'openDate' => $event->open_date->format('Y-m-d'),
                    'openDateComment' => $event->open_date_comment ?? '',
                    'area' => $event->area ?? '',
                    'applicationDeadline' => $event->application_deadline?->format('Y-m-d'),
                    'capacity' => $event->capacity,
                    'images' => $event->images->map([CommunityEventSerializer::class, 'image'])->all(),
                ],
            ]),
        ]);
    }

    public function update(UpdateEventRequest $request, CommunityEvent $event, UpdateEvent $action): RedirectResponse
    {
        try {
            $action($this->viewer(), $event, $request->toData(), $request->file('images', []), $request->input('remove_images', []));
        } catch (CommunityEventActionException) {
            abort(404);
        }

        return $this->redirectToEvent($request, $event)->with('status', __('Event updated.'));
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
        return redirect()->route(SurfaceResolver::redirectName($request, 'community.show'), $community)
            ->with('status', __('Event deleted.'));
    }

    public function memberList(Request $request, CommunityEvent $event, EventParticipants $query): View|InertiaResponse
    {
        abort_unless(CommunityEventAccess::canViewEvent($event, $this->viewer()), 404);
        $participants = $query($event);

        return $this->respondWith($request, 'community', [
            SurfaceResolver::CLASSIC => function () use ($event, $participants) {
                $this->markLocalNavCommunity($event->community);

                return view('community-event.member-list', [
                    'event' => $event,
                    'participants' => $participants,
                ]);
            },
            SurfaceResolver::MODERN => fn () => Inertia::render('community/event/members', [
                'event' => CommunityEventSerializer::detail($event),
                'participants' => CommunityEventSerializer::participantPaginator($participants),
            ]),
        ]);
    }

    /** Redirect to the event show page on the surface the request came from (both key off {event}). */
    private function redirectToEvent(Request $request, CommunityEvent $event): RedirectResponse
    {
        return redirect()->route(SurfaceResolver::redirectName($request, 'communityEvent.show'), $event);
    }

    /** Render a Classic-only confirm view with the OpenPNE 3 page_{module}_{action} body id. */
    private function classic(string $view, array $data = []): View
    {
        $community = ($data['event'] ?? null)?->community;
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
