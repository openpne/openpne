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
use App\Features\Notifications\ConsumeNotificationRows;
use App\Features\Notifications\NotificationTarget;
use App\Files\ImageEdit;
use App\Http\Controllers\Concerns\RespondsWithSurface;
use App\Http\Controllers\Controller;
use App\Http\Requests\GroupTopic\StoreTopicRequest;
use App\Http\Requests\GroupTopic\UpdateTopicRequest;
use App\LinkCard\LinkCardSync;
use App\Models\Group;
use App\Models\GroupTopic;
use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use App\Support\SurfaceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Board-level gates key on Group and call GroupTopicAccess directly; topic-level gates go through
 * GroupTopicPolicy via Gate. Every failure is a 404, hiding the topic's existence.
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

    public function show(Request $request, int $topic, ShowTopic $query, LinkCardSync $linkCards, ConsumeNotificationRows $feedRows): View|InertiaResponse
    {
        $found = $query($topic);
        abort_if($found === null, 404);
        $viewer = $this->viewer();
        abort_unless(GroupTopicAccess::canViewTopic($found, $viewer), 404);
        // After the authorization decision, and only on the detail page: a board index renders many
        // topics, and asking on each would queue a page's worth of jobs for someone scrolling past.
        $linkCards->ensure($found);
        $feedRows->markTargetsRead((int) $viewer->getKey(), NotificationTarget::topic((int) $found->getKey()));

        return $this->respondWith($request, 'group', [
            SurfaceResolver::CLASSIC => function () use ($request, $found, $viewer, $linkCards) {
                $this->markLocalNavGroup($found->group);

                $thread = GroupTopicCommentThread::paginate($found, $request->query('order'), $request->query('page'));
                // The comments this page renders, as the root was above: a comment is a body of its
                // own, and this is the page it is read on (LinkCardSync::ensureAll).
                $linkCards->ensureAll($thread->comments);

                return view('group-topic.show', [
                    'commentReplyLink' => (bool) app(SnsSettingService::class)->get(SnsSettingKey::GroupTopicCommentReply),
                    'topic' => $found,
                    'thread' => $thread,
                    'canComment' => GroupTopicAccess::canComment($found, $viewer),
                    'canEdit' => GroupTopicAccess::canEditTopic($found, $viewer),
                ]);
            },
            SurfaceResolver::MODERN => function () use ($request, $found, $viewer, $linkCards) {
                $found->loadMissing('member.avatar.file');
                $thread = GroupTopicCommentThread::paginate($found, $request->query('order'), $request->query('page'));
                $linkCards->ensureAll($thread->comments);

                return Inertia::render('group/topic/show', [
                    'group' => GroupSerializer::summary($found->group),
                    'topic' => GroupTopicSerializer::detail($found, $viewer),
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
                'topic' => GroupTopicSerializer::detail($topic, $this->viewer()),
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

    /** Both surfaces key off {topic}, so $request selects nothing here. */
    private function redirectToTopic(Request $request, GroupTopic $topic): RedirectResponse
    {
        return redirect()->route('group.topics.show', $topic);
    }

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
