<?php

namespace App\Features\CommunityTopic;

use App\Compat\RouteParityRegistry;
use App\Features\Community\Serializers\CommunitySerializer;
use App\Features\CommunityTopic\Actions\CreateTopic;
use App\Features\CommunityTopic\Actions\DeleteTopic;
use App\Features\CommunityTopic\Actions\UpdateTopic;
use App\Features\CommunityTopic\Exceptions\CommunityTopicActionException;
use App\Features\CommunityTopic\Queries\ListCommunityTopics;
use App\Features\CommunityTopic\Queries\ShowTopic;
use App\Features\CommunityTopic\Serializers\CommunityTopicSerializer;
use App\Http\Controllers\Concerns\RespondsWithSurface;
use App\Http\Controllers\Controller;
use App\Http\Requests\CommunityTopic\StoreTopicRequest;
use App\Http\Requests\CommunityTopic\UpdateTopicRequest;
use App\Models\Community;
use App\Models\CommunityTopic;
use App\Models\Member;
use App\Support\SurfaceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Community topic board, dual-surface: each action serves Classic Blade or Modern Inertia per
 * SurfaceResolver. The board-level gates (view a community's board, post a topic) key on Community,
 * so they call CommunityTopicAccess directly; the topic-level gates (edit/delete) go through the
 * auto-discovered CommunityTopicPolicy via Gate. Every failure is a 404, hiding the topic's
 * existence. The Classic community localNav side effect runs only in the Classic branch. showDelete
 * stays a Classic-only GET confirm page — Modern confirms delete inline (Radix AlertDialog).
 */
class CommunityTopicController extends Controller
{
    use RespondsWithSurface;

    public function index(Request $request, Community $community, ListCommunityTopics $query): View|InertiaResponse
    {
        $viewer = $this->viewer();
        abort_unless(CommunityTopicAccess::canViewBoard($community, $viewer), 404);
        $topics = $query($community);
        $canPost = CommunityTopicAccess::canPostTopic($community, $viewer);

        return $this->respondWith($request, 'community', [
            SurfaceResolver::CLASSIC => function () use ($community, $topics, $canPost) {
                $this->markLocalNavCommunity($community);

                return view('community-topic.index', [
                    'community' => $community,
                    'topics' => $topics,
                    'canPost' => $canPost,
                ]);
            },
            SurfaceResolver::MODERN => fn () => Inertia::render('community/topic/index', [
                'community' => CommunitySerializer::summary($community),
                'topics' => CommunityTopicSerializer::paginator($topics),
                'canPost' => $canPost,
            ]),
        ]);
    }

    public function show(Request $request, int $topic, ShowTopic $query): View|InertiaResponse
    {
        $found = $query($topic);
        abort_if($found === null, 404);
        $viewer = $this->viewer();
        abort_unless(CommunityTopicAccess::canViewTopic($found, $viewer), 404);

        return $this->respondWith($request, 'community', [
            SurfaceResolver::CLASSIC => function () use ($request, $found, $viewer) {
                $this->markLocalNavCommunity($found->community);

                return view('community-topic.show', [
                    'topic' => $found,
                    'thread' => CommunityTopicCommentThread::paginate($found, $request->query('order'), $request->query('page')),
                    'canComment' => CommunityTopicAccess::canComment($found, $viewer),
                    'canEdit' => CommunityTopicAccess::canEditTopic($found, $viewer),
                ]);
            },
            SurfaceResolver::MODERN => function () use ($found, $viewer) {
                $found->loadMissing('member.avatar.file');
                $comments = $found->comments()->with(['member.avatar.file', 'images.file'])->orderBy('number')->get();

                return Inertia::render('community/topic/show', [
                    'community' => CommunitySerializer::summary($found->community),
                    'topic' => CommunityTopicSerializer::detail($found),
                    'comments' => CommunityTopicSerializer::comments($comments, $viewer),
                    'canComment' => CommunityTopicAccess::canComment($found, $viewer),
                    'canEdit' => CommunityTopicAccess::canEditTopic($found, $viewer),
                ]);
            },
        ]);
    }

    public function new(Request $request, Community $community): View|InertiaResponse
    {
        abort_unless(CommunityTopicAccess::canPostTopic($community, $this->viewer()), 404);

        return $this->respondWith($request, 'community', [
            SurfaceResolver::CLASSIC => function () use ($community) {
                $this->markLocalNavCommunity($community);

                return view('community-topic.new', ['community' => $community]);
            },
            SurfaceResolver::MODERN => fn () => Inertia::render('community/topic/edit', [
                'community' => CommunitySerializer::summary($community),
                'topic' => null,
            ]),
        ]);
    }

    public function store(StoreTopicRequest $request, Community $community, CreateTopic $action): RedirectResponse
    {
        try {
            $topic = $action($this->viewer(), $community, $request->toData(), $request->file('images', []));
        } catch (CommunityTopicActionException) {
            abort(404);
        }

        return $this->redirectToTopic($request, $topic)->with('status', __('%Topic% posted.'));
    }

    public function edit(Request $request, CommunityTopic $topic): View|InertiaResponse
    {
        abort_unless(Gate::allows('update', $topic), 404);
        $topic->loadMissing('community', 'images.file');

        return $this->respondWith($request, 'community', [
            SurfaceResolver::CLASSIC => function () use ($topic) {
                $this->markLocalNavCommunity($topic->community);

                return view('community-topic.edit', ['topic' => $topic]);
            },
            SurfaceResolver::MODERN => fn () => Inertia::render('community/topic/edit', [
                'community' => CommunitySerializer::summary($topic->community),
                'topic' => CommunityTopicSerializer::detail($topic),
            ]),
        ]);
    }

    public function update(UpdateTopicRequest $request, CommunityTopic $topic, UpdateTopic $action): RedirectResponse
    {
        try {
            $action($this->viewer(), $topic, $request->toData(), $request->file('images', []), $request->input('remove_images', []));
        } catch (CommunityTopicActionException) {
            abort(404);
        }

        return $this->redirectToTopic($request, $topic)->with('status', __('%Topic% updated.'));
    }

    public function showDelete(Request $request, CommunityTopic $topic): View
    {
        abort_unless(Gate::allows('delete', $topic), 404);

        return $this->classic('community-topic.delete', ['topic' => $topic]);
    }

    public function delete(Request $request, CommunityTopic $topic, DeleteTopic $action): RedirectResponse
    {
        $community = $topic->community;

        try {
            $action($this->viewer(), $topic);
        } catch (CommunityTopicActionException) {
            abort(404);
        }

        // OpenPNE 3 returns to the community home after deleting a topic.
        return redirect()->route(SurfaceResolver::redirectName($request, 'community.show'), $community)
            ->with('status', __('%Topic% deleted.'));
    }

    /** Redirect to the topic show page on the surface the request came from (both key off {topic}). */
    private function redirectToTopic(Request $request, CommunityTopic $topic): RedirectResponse
    {
        return redirect()->route(SurfaceResolver::redirectName($request, 'communityTopic.show'), $topic);
    }

    /** Render a Classic-only confirm view with the OpenPNE 3 page_{module}_{action} body id. */
    private function classic(string $view, array $data = []): View
    {
        $community = ($data['topic'] ?? null)?->community;
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
