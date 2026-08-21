<?php

namespace App\Features\GroupEvent;

use App\Compat\RouteParityRegistry;
use App\Features\Group\Serializers\GroupSerializer;
use App\Features\GroupEvent\Actions\CreateEvent;
use App\Features\GroupEvent\Actions\DeleteEvent;
use App\Features\GroupEvent\Actions\UpdateEvent;
use App\Features\GroupEvent\Exceptions\GroupEventActionException;
use App\Features\GroupEvent\Queries\EventParticipants;
use App\Features\GroupEvent\Queries\ListGroupEvents;
use App\Features\GroupEvent\Queries\ShowEvent;
use App\Features\GroupEvent\Serializers\GroupEventSerializer;
use App\Files\ImageEdit;
use App\Http\Controllers\Concerns\RespondsWithSurface;
use App\Http\Controllers\Controller;
use App\Http\Requests\GroupEvent\StoreEventRequest;
use App\Http\Requests\GroupEvent\UpdateEventRequest;
use App\LinkCard\LinkCardSync;
use App\Models\Group;
use App\Models\GroupEvent;
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
 * call GroupEventAccess directly; event-level gates (edit/delete) go through the auto-discovered
 * GroupEventPolicy via Gate. Every failure is a 404. The Classic community localNav side effect
 * runs only in the Classic branch. showDelete stays a Classic-only GET confirm page — Modern confirms
 * inline. RSVP (join/cancel) is posted through the comment endpoint (GroupEventCommentController).
 */
class GroupEventController extends Controller
{
    use RespondsWithSurface;

    public function index(Request $request, Group $group, ListGroupEvents $query): View|InertiaResponse
    {
        $viewer = $this->viewer();
        abort_unless(GroupEventAccess::canViewBoard($group, $viewer), 404);
        $events = $query($group);
        $canPost = GroupEventAccess::canPostEvent($group, $viewer);

        return $this->respondWith($request, 'group', [
            SurfaceResolver::CLASSIC => function () use ($group, $events, $canPost) {
                $this->markLocalNavGroup($group);

                return view('group-event.index', [
                    'group' => $group,
                    'events' => $events,
                    'canPost' => $canPost,
                ]);
            },
            SurfaceResolver::MODERN => fn () => Inertia::render('group/event/index', [
                'group' => GroupSerializer::summary($group),
                'events' => GroupEventSerializer::paginator($events),
                'canPost' => $canPost,
            ]),
        ]);
    }

    public function show(Request $request, int $event, ShowEvent $query, LinkCardSync $linkCards): View|InertiaResponse
    {
        $found = $query($event);
        abort_if($found === null, 404);
        $viewer = $this->viewer();
        abort_unless(GroupEventAccess::canViewEvent($found, $viewer), 404);
        $linkCards->ensure($found);

        return $this->respondWith($request, 'group', [
            SurfaceResolver::CLASSIC => function () use ($request, $found, $viewer, $linkCards) {
                $this->markLocalNavGroup($found->group);

                $thread = GroupEventCommentThread::paginate($found, $request->query('order'), $request->query('page'));
                // The comments this page renders, as the body was above: a comment is a body of its
                // own, and this is the page it is read on (LinkCardSync::ensureAll).
                $linkCards->ensureAll($thread->comments);

                return view('group-event.show', [
                    'event' => $found,
                    'thread' => $thread,
                    'canComment' => GroupEventAccess::canComment($found, $viewer),
                    'canEdit' => GroupEventAccess::canEditEvent($found, $viewer),
                    'isParticipant' => $found->isParticipant($viewer),
                    'isClosed' => $found->isClosed(),
                    'isExpired' => $found->isExpired(),
                    'isFull' => $found->isFull(),
                ]);
            },
            SurfaceResolver::MODERN => function () use ($request, $found, $viewer, $linkCards) {
                $found->loadMissing('member.avatar.file');
                // Reuse the id-ordered, size-20 pager so Modern matches Classic and never serializes
                // an unbounded thread (same contract as the topic board).
                $thread = GroupEventCommentThread::paginate($found, $request->query('order'), $request->query('page'));
                $linkCards->ensureAll($thread->comments);

                return Inertia::render('group/event/show', [
                    'group' => GroupSerializer::summary($found->group),
                    'event' => GroupEventSerializer::detail($found),
                    'thread' => GroupEventSerializer::thread($thread, $viewer),
                    'canComment' => GroupEventAccess::canComment($found, $viewer),
                    'canEdit' => GroupEventAccess::canEditEvent($found, $viewer),
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
        abort_unless(GroupEventAccess::canPostEvent($group, $this->viewer()), 404);

        return $this->respondWith($request, 'group', [
            SurfaceResolver::CLASSIC => function () use ($group) {
                $this->markLocalNavGroup($group);

                return view('group-event.new', ['group' => $group]);
            },
            SurfaceResolver::MODERN => fn () => Inertia::render('group/event/edit', [
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
        } catch (GroupEventActionException) {
            abort(404);
        }

        return $this->redirectToEvent($request, $event)->with('status', __('Event posted.'));
    }

    public function edit(Request $request, GroupEvent $event): View|InertiaResponse
    {
        abort_unless(Gate::allows('update', $event), 404);
        $event->loadMissing('group', 'images.file');

        return $this->respondWith($request, 'group', [
            SurfaceResolver::CLASSIC => function () use ($event) {
                $this->markLocalNavGroup($event->group);

                return view('group-event.edit', ['event' => $event]);
            },
            SurfaceResolver::MODERN => fn () => Inertia::render('group/event/edit', [
                'group' => GroupSerializer::summary($event->group),
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
                    'images' => $event->images->map([GroupEventSerializer::class, 'image'])->all(),
                ],
                'composeEditor' => $this->viewer()->composeEditor()->value,
            ]),
        ]);
    }

    public function update(UpdateEventRequest $request, GroupEvent $event, UpdateEvent $action): RedirectResponse
    {
        try {
            $action($this->viewer(), $event, $request->toData(), ImageEdit::fromRequest($request));
        } catch (GroupEventActionException) {
            abort(404);
        }

        return $this->redirectToEvent($request, $event)->with('status', __('Event updated.'));
    }

    public function showDelete(Request $request, GroupEvent $event): View|RedirectResponse
    {
        abort_unless(Gate::allows('delete', $event), 404);

        // Modern confirms deletion inline — send a Modern viewer back to the event.
        if (SurfaceResolver::resolve($request, 'group') === SurfaceResolver::MODERN) {
            return redirect()->route('group.events.show', $event);
        }

        return $this->classic('group-event.delete', ['event' => $event]);
    }

    public function delete(Request $request, GroupEvent $event, DeleteEvent $action): RedirectResponse
    {
        $group = $event->group;

        try {
            $action($this->viewer(), $event);
        } catch (GroupEventActionException) {
            abort(404);
        }

        // OpenPNE 3 returns to the community home after deleting an event.
        return redirect()->route('group.show', $group)
            ->with('status', __('Event deleted.'));
    }

    public function memberList(Request $request, GroupEvent $event, EventParticipants $query): View|InertiaResponse
    {
        abort_unless(GroupEventAccess::canViewEvent($event, $this->viewer()), 404);
        $participants = $query($event);

        return $this->respondWith($request, 'group', [
            SurfaceResolver::CLASSIC => function () use ($event, $participants) {
                $this->markLocalNavGroup($event->group);

                return view('group-event.member-list', [
                    'event' => $event,
                    'participants' => $participants,
                ]);
            },
            SurfaceResolver::MODERN => function () use ($event, $participants) {
                $event->loadMissing('group');

                return Inertia::render('group/event/members', [
                    'group' => GroupSerializer::summary($event->group),
                    'event' => GroupEventSerializer::detail($event),
                    'participants' => GroupEventSerializer::participantPaginator($participants),
                ]);
            },
        ]);
    }

    /** Redirect to the event show page on the surface the request came from (both key off {event}). */
    private function redirectToEvent(Request $request, GroupEvent $event): RedirectResponse
    {
        return redirect()->route('group.events.show', $event);
    }

    /** Render a Classic-only confirm view with the OpenPNE 3 page_{module}_{action} body id. */
    private function classic(string $view, array $data = []): View
    {
        $group = ($data['event'] ?? null)?->group;
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
