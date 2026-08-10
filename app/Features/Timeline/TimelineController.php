<?php

namespace App\Features\Timeline;

use App\Compat\RouteParityRegistry;
use App\Features\Member\Serializers\MemberRefSerializer;
use App\Features\Timeline\Actions\CreateReply;
use App\Features\Timeline\Actions\CreateTimelinePost;
use App\Features\Timeline\Actions\DeleteTimelinePost;
use App\Features\Timeline\Queries\HomeFeed;
use App\Features\Timeline\Queries\MemberTimeline;
use App\Features\Timeline\Queries\MentionCandidates;
use App\Features\Timeline\Queries\ShowTimelinePost;
use App\Features\Timeline\Serializers\TimelinePostSerializer;
use App\Http\Controllers\Concerns\RespondsWithSurface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Timeline\StoreReplyRequest;
use App\Http\Requests\Timeline\StoreTimelinePostRequest;
use App\LinkCard\LinkCardSync;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\SurfaceResolver;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class TimelineController extends Controller
{
    use RespondsWithSurface;

    public function index(Request $request, HomeFeed $query): View|InertiaResponse
    {
        $viewer = $this->viewer();
        $posts = $query($viewer);

        return $this->respondWith($request, 'timeline', [
            SurfaceResolver::CLASSIC => fn () => view('timeline.index', [
                'viewer' => $viewer,
                'posts' => $posts,
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('timeline/index', [
                'viewerId' => $viewer->getKey(),
                'posts' => TimelinePostSerializer::paginator($posts),
            ]),
        ]);
    }

    public function member(Request $request, MemberTimeline $query, Member $member): View|InertiaResponse
    {
        $viewer = $this->viewer();
        $owner = $this->memberSubject($member);
        $posts = $query($viewer, $owner);

        return $this->respondWith($request, 'timeline', [
            SurfaceResolver::CLASSIC => fn () => view('timeline.member', [
                'owner' => $owner,
                'posts' => $posts,
            ]),
            SurfaceResolver::MODERN => function () use ($owner, $viewer, $posts) {
                // The owner ref draws the chrome's scope avatar (Modern only, so Classic pays nothing).
                $owner->loadMissing('avatar.file');

                return Inertia::render('timeline/member', [
                    'owner' => MemberRefSerializer::ref($owner),
                    'isOwner' => $viewer->is($owner),
                    'viewerId' => $viewer->getKey(),
                    'posts' => TimelinePostSerializer::paginator($posts),
                ]);
            },
        ]);
    }

    public function show(Request $request, int $timelinePost, ShowTimelinePost $query, LinkCardSync $linkCards): View|InertiaResponse|RedirectResponse
    {
        $viewer = $this->viewer();
        $post = $query($viewer, $timelinePost);
        abort_if($post === null, 404);

        // A reply permalink re-centered to its thread root; send it to the root's canonical URL so a
        // thread has one address.
        if ($post->getKey() !== $timelinePost) {
            return redirect()->route('timeline.show', ['timelinePost' => $post->getKey()]);
        }

        // ShowTimelinePost already gated the block (null → 404 above); record the author for the
        // Classic friend localNav when viewing someone else's post.
        $this->markLocalNavSubject($post->member);
        // Eager-load the replies' images too: the serializer reads each post's images, so loading
        // only replies.member would lazy-load one (empty, by the no-image contract) query per reply.
        $post->load(['member.avatar.file', 'replies.member.avatar.file', 'replies.images.file']);
        // The thread root only. Replies share this table but render as a thread underneath, where a
        // stack of cards would read as noise — and asking per reply would queue a job each. Placed
        // after the reply-permalink redirect above, so a request that never renders queues nothing.
        $linkCards->ensure($post);

        return $this->respondWith($request, 'timeline', [
            SurfaceResolver::CLASSIC => fn () => view('timeline.show', [
                'post' => $post,
                'viewer' => $viewer,
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('timeline/show', [
                'post' => TimelinePostSerializer::entry($post),
                'replies' => array_map([TimelinePostSerializer::class, 'entry'], $post->replies->all()),
                'viewerId' => $viewer->getKey(),
            ]),
        ]);
    }

    public function new(Request $request): View|InertiaResponse
    {
        return $this->respondWith($request, 'timeline', [
            SurfaceResolver::CLASSIC => fn () => view('timeline.new', [
                'visibilityOptions' => TimelineVisibility::options(),
                'defaultVisibility' => Visibility::Members,
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('timeline/new', [
                'defaultVisibility' => (string) Visibility::Members->value,
                // Drive the Modern select from the same selectable audiences as Classic, so it
                // can never submit an option (e.g. Open) it does not visibly render.
                'visibilityOptions' => array_map(
                    fn (Visibility $option): array => ['value' => (string) $option->value, 'label' => $option->label()],
                    TimelineVisibility::options(),
                ),
            ]),
        ]);
    }

    /** Members the compose form's @mention picker may offer for its search term. */
    public function mentionCandidates(Request $request, MentionCandidates $query): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        $candidates = $query($this->viewer(), $request->string('q')->value());

        return response()->json([
            'candidates' => array_map([MemberRefSerializer::class, 'ref'], $candidates->all()),
        ]);
    }

    public function store(StoreTimelinePostRequest $request, CreateTimelinePost $action): RedirectResponse
    {
        $viewer = $this->viewer();
        $action($viewer, $request->toData(), $request->file('image'));

        // The inline forms return to their own page (allowlisted token), page 1, where the fresh
        // post now leads the feed; the standalone compose page keeps its member-timeline landing.
        return redirect()
            ->route($request->returnRoute() ?? 'timeline.member', $request->returnRoute() !== null ? [] : ['member' => $viewer->getKey()])
            ->with('status', __('Posted.'));
    }

    public function storeReply(StoreReplyRequest $request, int $timelinePost, ShowTimelinePost $query, CreateReply $action): RedirectResponse
    {
        $viewer = $this->viewer();
        // Replying requires viewing the thread; ShowTimelinePost re-centers to the root and applies
        // the same clearance/block gate, so a reply always attaches to a viewable top-level post.
        $root = $query($viewer, $timelinePost);
        abort_if($root === null, 404);

        $action($viewer, $root, $request->validated('body'), $request->toMentions());

        return redirect()
            ->route('timeline.show', ['timelinePost' => $root->getKey()])
            ->with('status', __('Reply posted.'));
    }

    public function showDelete(Request $request, TimelinePost $timelinePost): View|RedirectResponse
    {
        abort_unless($this->viewer()->is($timelinePost->member), 404);

        // Modern confirms delete inline (Radix AlertDialog) — send a Modern viewer to the post's thread.
        if (SurfaceResolver::resolve($request, 'timeline') === SurfaceResolver::MODERN) {
            return redirect()->route('timeline.show', ['timelinePost' => $timelinePost->in_reply_to_id ?? $timelinePost->getKey()]);
        }

        return $this->classic('timeline.delete', ['post' => $timelinePost]);
    }

    public function delete(Request $request, TimelinePost $timelinePost, DeleteTimelinePost $action): RedirectResponse
    {
        $viewer = $this->viewer();
        abort_unless($viewer->is($timelinePost->member), 404);
        // Capture the thread root before the row is gone: deleting a reply returns to its thread,
        // deleting a top-level post returns to the author's timeline.
        $parentId = $timelinePost->in_reply_to_id;
        $action($timelinePost);

        if ($parentId !== null) {
            return redirect()
                ->route('timeline.show', ['timelinePost' => $parentId])
                ->with('status', __('Reply deleted.'));
        }

        return redirect()
            ->route('timeline.member', ['member' => $viewer->getKey()])
            ->with('status', __('Post deleted.'));
    }

    /** Render a Classic-only confirm view with the OpenPNE 3 page_{module}_{action} body id. */
    private function classic(string $view, array $data = []): View
    {
        return view($view, $data)->with('pageId', RouteParityRegistry::bodyId($this->routeName()));
    }

    private function routeName(): string
    {
        $route = request()->route();

        return $route !== null ? (string) $route->getName() : '';
    }
}
