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
use App\Features\CommunityEvent\Serializers\CommunityEventSerializer;
use App\Features\Group\Serializers\GroupSerializer;
use App\Files\ImageEdit;
use App\Http\Controllers\Concerns\RespondsWithSurface;
use App\Http\Controllers\Controller;
use App\Http\Requests\CommunityEvent\StoreEventRequest;
use App\Http\Requests\CommunityEvent\UpdateEventRequest;
use App\LinkCard\LinkCardSync;
use App\Models\CommunityEvent;
use App\Models\Group;
use App\Support\SurfaceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Group events, dual-surface: each action serves Classic Blade or Modern Inertia per
 * SurfaceResolver. Board-level gates (view a community's events, post one) key on Group, so they
 * call CommunityEventAccess directly; event-level gates (edit/delete) go through the auto-discovered
 * CommunityEventPolicy via Gate. Every failure is a 404. The Classic community localNav side effect
 * runs only in the Classic branch. showDelete stays a Classic-only GET confirm page — Modern confirms
 * inline. RSVP (join/cancel) is posted through the comment endpoint (CommunityEventCommentController).
 */
class CommunityEventController extends Controller
{
    use RespondsWithSurface;

    public function index(Request $request, Group $group, ListCommunityEvents $query): View|InertiaResponse
    {
        $viewer = $this->viewer();
        abort_unless(CommunityEventAccess::canViewBoard($group, $viewer), 404);
        $events = $query($group);
        $canPost = CommunityEventAccess::canPostEvent($group, $viewer);

        return $this->respondWith($request, 'group', [
            SurfaceResolver::CLASSIC => function () use ($group, $events, $canPost) {
                $this->markLocalNavGroup($group);

                return view('community-event.index', [
                    'group' => $group,
                    'events' => $events,
                    'canPost' => $canPost,
                ]);
            },
            SurfaceResolver::MODERN => fn () => Inertia::render('community/event/index', [
                'group' => GroupSerializer::summary($group),
                'events' => CommunityEventSerializer::paginator($events),
                'canPost' => $canPost,
            ]),
        ]);
    }

    public function show(Request $request, int $event, ShowEvent $query, LinkCardSync $linkCards): View|InertiaResponse
    {
        $found = $query($event);
        abort_if($found === null, 404);
        $viewer = $this->viewer();
        abort_unless(CommunityEventAccess::canViewEvent($found, $viewer), 404);
        $linkCards->ensure($found);

        return $this->respondWith($request, 'group', [
            SurfaceResolver::CLASSIC => function () use ($request, $found, $viewer) {
                $this->markLocalNavGroup($found->community);

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
                    'group' => GroupSerializer::summary($found->community),
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

    public function new(Request $request, Group $group): View|InertiaResponse
    {
        abort_unless(CommunityEventAccess::canPostEvent($group, $this->viewer()), 404);

        return $this->respondWith($request, 'group', [
            SurfaceResolver::CLASSIC => function () use ($group) {
                $this->markLocalNavGroup($group);

                return view('community-event.new', ['group' => $group]);
            },
            SurfaceResolver::MODERN => fn () => Inertia::render('community/event/edit', [
                'group' => GroupSerializer::summary($group),
                'event' => null,
                'composeEditor' => $this->viewer()->composeEditor()->value,
            ]),
        ]);
    }

    public function store(StoreEventRequest $request, Group $group, CreateEvent $action): RedirectResponse
    {
        try {
            $event = $action($this->viewer(), $group, $request->toData(), $request->file('images', []));
        } catch (CommunityEventActionException) {
            abort(404);
        }

        return $this->redirectToEvent($request, $event)->with('status', __('Event posted.'));
    }

    public function edit(Request $request, CommunityEvent $event): View|InertiaResponse
    {
        abort_unless(Gate::allows('update', $event), 404);
        $event->loadMissing('community', 'images.file');

        return $this->respondWith($request, 'group', [
            SurfaceResolver::CLASSIC => function () use ($event) {
                $this->markLocalNavGroup($event->community);

                return view('community-event.edit', ['event' => $event]);
            },
            SurfaceResolver::MODERN => fn () => Inertia::render('community/event/edit', [
                'group' => GroupSerializer::summary($event->community),
                // Form-shaped: the date widgets need Y-m-d, not the ISO datetime the detail carries.
                'event' => [
                    'id' => $event->getKey(),
                    'name' => $event->name,
                    'body' => $event->body,
                    'format' => $event->format->value,
                    'openDate' => $event->open_date->format('Y-m-d'),
                    'openDateComment' => $event->open_date_comment ?? '',
                    'area' => $event->area ?? '',
                    'applicationDeadline' => $event->application_deadline?->format('Y-m-d'),
                    'capacity' => $event->capacity,
                    'images' => $event->images->map([CommunityEventSerializer::class, 'image'])->all(),
                ],
                'composeEditor' => $this->viewer()->composeEditor()->value,
            ]),
        ]);
    }

    public function update(UpdateEventRequest $request, CommunityEvent $event, UpdateEvent $action): RedirectResponse
    {
        try {
            $action($this->viewer(), $event, $request->toData(), ImageEdit::fromRequest($request));
        } catch (CommunityEventActionException) {
            abort(404);
        }

        return $this->redirectToEvent($request, $event)->with('status', __('Event updated.'));
    }

    public function showDelete(Request $request, CommunityEvent $event): View|RedirectResponse
    {
        abort_unless(Gate::allows('delete', $event), 404);

        // Modern confirms deletion inline — send a Modern viewer back to the event.
        if (SurfaceResolver::resolve($request, 'group') === SurfaceResolver::MODERN) {
            return redirect()->route('communityEvent.show', $event);
        }

        return $this->classic('community-event.delete', ['event' => $event]);
    }

    public function delete(Request $request, CommunityEvent $event, DeleteEvent $action): RedirectResponse
    {
        $group = $event->community;

        try {
            $action($this->viewer(), $event);
        } catch (CommunityEventActionException) {
            abort(404);
        }

        // OpenPNE 3 returns to the community home after deleting an event.
        return redirect()->route('group.show', $group)
            ->with('status', __('Event deleted.'));
    }

    public function memberList(Request $request, CommunityEvent $event, EventParticipants $query): View|InertiaResponse
    {
        abort_unless(CommunityEventAccess::canViewEvent($event, $this->viewer()), 404);
        $participants = $query($event);

        return $this->respondWith($request, 'group', [
            SurfaceResolver::CLASSIC => function () use ($event, $participants) {
                $this->markLocalNavGroup($event->community);

                return view('community-event.member-list', [
                    'event' => $event,
                    'participants' => $participants,
                ]);
            },
            SurfaceResolver::MODERN => function () use ($event, $participants) {
                $event->loadMissing('community');

                return Inertia::render('community/event/members', [
                    'group' => GroupSerializer::summary($event->community),
                    'event' => CommunityEventSerializer::detail($event),
                    'participants' => CommunityEventSerializer::participantPaginator($participants),
                ]);
            },
        ]);
    }

    /** Redirect to the event show page on the surface the request came from (both key off {event}). */
    private function redirectToEvent(Request $request, CommunityEvent $event): RedirectResponse
    {
        return redirect()->route('communityEvent.show', $event);
    }

    /** Render a Classic-only confirm view with the OpenPNE 3 page_{module}_{action} body id. */
    private function classic(string $view, array $data = []): View
    {
        $group = ($data['event'] ?? null)?->community;
        if ($group instanceof Group) {
            $this->markLocalNavGroup($group);
        }

        return view($view, $data)->with('pageId', RouteParityRegistry::bodyId($this->routeName()));
    }

    private function routeName(): string
    {
        $route = request()->route();

        return $route !== null ? (string) $route->getName() : '';
    }
}
