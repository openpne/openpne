<?php

namespace App\Features\GroupTopic;

use App\Compat\RouteParityRegistry;
use App\Features\Group\Serializers\GroupSerializer;
use App\Features\GroupTopic\Actions\CreateTopic;
use App\Features\GroupTopic\Actions\DeleteTopic;
use App\Features\GroupTopic\Actions\UpdateTopic;
use App\Features\GroupTopic\Exceptions\GroupTopicActionException;
use App\Features\GroupTopic\Queries\ListGroupTopics;
use App\Features\GroupTopic\Queries\ShowTopic;
use App\Features\GroupTopic\Serializers\GroupTopicSerializer;
use App\Files\ImageEdit;
use App\Http\Controllers\Concerns\RespondsWithSurface;
use App\Http\Controllers\Controller;
use App\Http\Requests\GroupTopic\StoreTopicRequest;
use App\Http\Requests\GroupTopic\UpdateTopicRequest;
use App\LinkCard\LinkCardSync;
use App\Models\Group;
use App\Models\GroupTopic;
use App\Support\SurfaceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Group topic board, dual-surface: each action serves Classic Blade or Modern Inertia per
 * SurfaceResolver. The board-level gates (view a group's board, post a topic) key on Group,
 * so they call GroupTopicAccess directly; the topic-level gates (edit/delete) go through the
 * auto-discovered GroupTopicPolicy via Gate. Every failure is a 404, hiding the topic's
 * existence. The Classic group localNav side effect runs only in the Classic branch. showDelete
 * stays a Classic-only GET confirm page — Modern confirms delete inline (Radix AlertDialog).
 */
class GroupTopicController extends Controller
{
    use RespondsWithSurface;

    public function index(Request $request, Group $group, ListGroupTopics $query): View|InertiaResponse
    {
        $viewer = $this->viewer();
        abort_unless(GroupTopicAccess::canViewBoard($group, $viewer), 404);
        $topics = $query($group);
        $canPost = GroupTopicAccess::canPostTopic($group, $viewer);

        return $this->respondWith($request, 'group', [
            SurfaceResolver::CLASSIC => function () use ($group, $topics, $canPost) {
                $this->markLocalNavGroup($group);

                return view('group-topic.index', [
                    'group' => $group,
                    'topics' => $topics,
                    'canPost' => $canPost,
                ]);
            },
            SurfaceResolver::MODERN => fn () => Inertia::render('group/topic/index', [
                'group' => GroupSerializer::summary($group),
                'topics' => GroupTopicSerializer::paginator($topics),
                'canPost' => $canPost,
            ]),
        ]);
    }

    public function show(Request $request, int $topic, ShowTopic $query, LinkCardSync $linkCards): View|InertiaResponse
    {
        $found = $query($topic);
        abort_if($found === null, 404);
        $viewer = $this->viewer();
        abort_unless(GroupTopicAccess::canViewTopic($found, $viewer), 404);
        // After the authorization decision, and only on the detail page: a board index renders many
        // topics, and asking on each would queue a page's worth of jobs for someone scrolling past.
        $linkCards->ensure($found);

        return $this->respondWith($request, 'group', [
            SurfaceResolver::CLASSIC => function () use ($request, $found, $viewer) {
                $this->markLocalNavGroup($found->group);

                return view('group-topic.show', [
                    'topic' => $found,
                    'thread' => GroupTopicCommentThread::paginate($found, $request->query('order'), $request->query('page')),
                    'canComment' => GroupTopicAccess::canComment($found, $viewer),
                    'canEdit' => GroupTopicAccess::canEditTopic($found, $viewer),
                ]);
            },
            SurfaceResolver::MODERN => function () use ($request, $found, $viewer) {
                $found->loadMissing('member.avatar.file');
                // Reuse the Classic thread pager (id-ordered, size 20, reversible) so Modern shows
                // comments in the same order as Classic — number is racy on migrated data — and never
                // serializes an unbounded thread in one response.
                $thread = GroupTopicCommentThread::paginate($found, $request->query('order'), $request->query('page'));

                return Inertia::render('group/topic/show', [
                    'group' => GroupSerializer::summary($found->group),
                    'topic' => GroupTopicSerializer::detail($found),
                    'thread' => GroupTopicSerializer::thread($thread, $viewer),
                    'canComment' => GroupTopicAccess::canComment($found, $viewer),
                    'canEdit' => GroupTopicAccess::canEditTopic($found, $viewer),
                ]);
            },
        ]);
    }

    public function new(Request $request, Group $group): View|InertiaResponse
    {
        abort_unless(GroupTopicAccess::canPostTopic($group, $this->viewer()), 404);

        return $this->respondWith($request, 'group', [
            SurfaceResolver::CLASSIC => function () use ($group) {
                $this->markLocalNavGroup($group);

                return view('group-topic.new', ['group' => $group]);
            },
            SurfaceResolver::MODERN => fn () => Inertia::render('group/topic/edit', [
                'group' => GroupSerializer::summary($group),
                'topic' => null,
                'composeEditor' => $this->viewer()->composeEditor()->value,
            ]),
        ]);
    }

    public function store(StoreTopicRequest $request, Group $group, CreateTopic $action): RedirectResponse
    {
        try {
            $topic = $action($this->viewer(), $group, $request->toData(), $request->file('images', []));
        } catch (GroupTopicActionException) {
            abort(404);
        }

        return $this->redirectToTopic($request, $topic)->with('status', __('%Topic% posted.'));
    }

    public function edit(Request $request, GroupTopic $topic): View|InertiaResponse
    {
        abort_unless(Gate::allows('update', $topic), 404);
        $topic->loadMissing('group', 'images.file');

        return $this->respondWith($request, 'group', [
            SurfaceResolver::CLASSIC => function () use ($topic) {
                $this->markLocalNavGroup($topic->group);

                return view('group-topic.edit', ['topic' => $topic]);
            },
            SurfaceResolver::MODERN => fn () => Inertia::render('group/topic/edit', [
                'group' => GroupSerializer::summary($topic->group),
                'topic' => GroupTopicSerializer::detail($topic),
                'composeEditor' => $this->viewer()->composeEditor()->value,
            ]),
        ]);
    }

    public function update(UpdateTopicRequest $request, GroupTopic $topic, UpdateTopic $action): RedirectResponse
    {
        try {
            $action($this->viewer(), $topic, $request->toData(), ImageEdit::fromRequest($request));
        } catch (GroupTopicActionException) {
            abort(404);
        }

        return $this->redirectToTopic($request, $topic)->with('status', __('%Topic% updated.'));
    }

    public function showDelete(Request $request, GroupTopic $topic): View|RedirectResponse
    {
        abort_unless(Gate::allows('delete', $topic), 404);

        // Modern confirms deletion inline — send a Modern viewer back to the topic.
        if (SurfaceResolver::resolve($request, 'group') === SurfaceResolver::MODERN) {
            return redirect()->route('group.topics.show', $topic);
        }

        return $this->classic('group-topic.delete', ['topic' => $topic]);
    }

    public function delete(Request $request, GroupTopic $topic, DeleteTopic $action): RedirectResponse
    {
        $group = $topic->group;

        try {
            $action($this->viewer(), $topic);
        } catch (GroupTopicActionException) {
            abort(404);
        }

        // OpenPNE 3 returns to the group home after deleting a topic.
        return redirect()->route('group.show', $group)
            ->with('status', __('%Topic% deleted.'));
    }

    /** Redirect to the topic show page on the surface the request came from (both key off {topic}). */
    private function redirectToTopic(Request $request, GroupTopic $topic): RedirectResponse
    {
        return redirect()->route('group.topics.show', $topic);
    }

    /** Render a Classic-only confirm view with the OpenPNE 3 page_{module}_{action} body id. */
    private function classic(string $view, array $data = []): View
    {
        $group = ($data['topic'] ?? null)?->group;
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
