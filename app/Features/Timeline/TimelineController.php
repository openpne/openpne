<?php

namespace App\Features\Timeline;

use App\Features\Timeline\Actions\CreateReply;
use App\Features\Timeline\Actions\CreateTimelinePost;
use App\Features\Timeline\Actions\DeleteTimelinePost;
use App\Features\Timeline\Queries\HomeFeed;
use App\Features\Timeline\Queries\MemberTimeline;
use App\Features\Timeline\Queries\ShowTimelinePost;
use App\Features\Timeline\Serializers\TimelinePostSerializer;
use App\Http\Controllers\Concerns\RespondsWithSurface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Timeline\StoreReplyRequest;
use App\Http\Requests\Timeline\StoreTimelinePostRequest;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\SurfaceResolver;
use App\Support\Visibility;
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
            SurfaceResolver::MODERN => fn () => Inertia::render('timeline/member', [
                'owner' => ['id' => $owner->getKey(), 'name' => $owner->name],
                'isOwner' => $viewer->is($owner),
                'viewerId' => $viewer->getKey(),
                'posts' => TimelinePostSerializer::paginator($posts),
            ]),
        ]);
    }

    public function show(Request $request, int $timelinePost, ShowTimelinePost $query): View|InertiaResponse|RedirectResponse
    {
        $viewer = $this->viewer();
        $post = $query($viewer, $timelinePost);
        abort_if($post === null, 404);

        // A reply permalink re-centered to its thread root; send it to the root's canonical URL so a
        // thread has one address.
        if ($post->getKey() !== $timelinePost) {
            return redirect()->route(SurfaceResolver::redirectName($request, 'timeline.show'), ['timelinePost' => $post->getKey()]);
        }

        // ShowTimelinePost already gated the block (null → 404 above); record the author for the
        // Classic friend localNav when viewing someone else's post.
        $this->markLocalNavSubject($post->member);
        // Eager-load the replies' images too: the serializer reads each post's images, so loading
        // only replies.member would lazy-load one (empty, by the no-image contract) query per reply.
        $post->load(['replies.member', 'replies.images.file']);

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

    public function store(StoreTimelinePostRequest $request, CreateTimelinePost $action): RedirectResponse
    {
        $viewer = $this->viewer();
        $action($viewer, $request->toData(), $request->file('image'));

        return redirect()
            ->route(SurfaceResolver::redirectName($request, 'timeline.member'), ['member' => $viewer->getKey()])
            ->with('status', __('Posted.'));
    }

    public function storeReply(StoreReplyRequest $request, int $timelinePost, ShowTimelinePost $query, CreateReply $action): RedirectResponse
    {
        $viewer = $this->viewer();
        // Replying requires viewing the thread; ShowTimelinePost re-centers to the root and applies
        // the same clearance/block gate, so a reply always attaches to a viewable top-level post.
        $root = $query($viewer, $timelinePost);
        abort_if($root === null, 404);

        $action($viewer, $root, $request->validated('body'));

        return redirect()
            ->route(SurfaceResolver::redirectName($request, 'timeline.show'), ['timelinePost' => $root->getKey()])
            ->with('status', __('Reply posted.'));
    }

    public function showDelete(Request $request, TimelinePost $timelinePost): View|InertiaResponse
    {
        abort_unless($this->viewer()->is($timelinePost->member), 404);

        return $this->respondWith($request, 'timeline', [
            SurfaceResolver::CLASSIC => fn () => view('timeline.delete', ['post' => $timelinePost]),
            SurfaceResolver::MODERN => fn () => Inertia::render('timeline/delete', [
                'post' => TimelinePostSerializer::entry($timelinePost->load(['member', 'images.file'])),
            ]),
        ]);
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
                ->route(SurfaceResolver::redirectName($request, 'timeline.show'), ['timelinePost' => $parentId])
                ->with('status', __('Reply deleted.'));
        }

        return redirect()
            ->route(SurfaceResolver::redirectName($request, 'timeline.member'), ['member' => $viewer->getKey()])
            ->with('status', __('Post deleted.'));
    }

    private function viewer(): Member
    {
        $viewer = auth()->user();
        assert($viewer instanceof Member);

        return $viewer;
    }
}
