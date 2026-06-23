<?php

namespace App\Features\Timeline;

use App\Compat\RouteParityRegistry;
use App\Features\Timeline\Actions\CreateTimelinePost;
use App\Features\Timeline\Actions\DeleteTimelinePost;
use App\Features\Timeline\Queries\HomeFeed;
use App\Features\Timeline\Queries\MemberTimeline;
use App\Features\Timeline\Queries\ShowTimelinePost;
use App\Features\Timeline\Serializers\TimelinePostSerializer;
use App\Http\Controllers\Controller;
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
    public function index(Request $request, HomeFeed $query): View|InertiaResponse
    {
        $viewer = $this->viewer();
        $posts = $query($viewer);

        return $this->respondWith($request, [
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

        return $this->respondWith($request, [
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

    public function show(Request $request, int $timelinePost, ShowTimelinePost $query): View|InertiaResponse
    {
        $viewer = $this->viewer();
        $post = $query($viewer, $timelinePost);
        abort_if($post === null, 404);
        // ShowTimelinePost already gated the block (null → 404 above); record the author for the
        // Classic friend localNav when viewing someone else's post.
        $this->markLocalNavSubject($post->member);

        return $this->respondWith($request, [
            SurfaceResolver::CLASSIC => fn () => view('timeline.show', [
                'post' => $post,
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('timeline/show', [
                'post' => TimelinePostSerializer::entry($post),
            ]),
        ]);
    }

    public function new(Request $request): View|InertiaResponse
    {
        return $this->respondWith($request, [
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

    public function showDelete(Request $request, TimelinePost $timelinePost): View|InertiaResponse
    {
        abort_unless($this->viewer()->is($timelinePost->member), 404);

        return $this->respondWith($request, [
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
        $action($timelinePost);

        return redirect()
            ->route(SurfaceResolver::redirectName($request, 'timeline.member'), ['member' => $viewer->getKey()])
            ->with('status', __('Post deleted.'));
    }

    /**
     * @param  array{classic: callable(): (View|InertiaResponse), modern: callable(): (View|InertiaResponse)}  $responders
     */
    private function respondWith(Request $request, array $responders): View|InertiaResponse
    {
        $response = $responders[SurfaceResolver::resolve($request, 'timeline')]();

        // Classic body id is the OpenPNE 3 page_{module}_{action} hook, derived from the route
        // parity so it stays faithful to OpenPNE 3 (the controller holds no copy). Canonicalize
        // first: a /m/* route that fell back to Classic carries the modern name.
        if ($response instanceof View) {
            $name = SurfaceResolver::canonicalName($request->route()->getName());
            $response->with('pageId', RouteParityRegistry::bodyId($name));
        }

        return $response;
    }

    private function viewer(): Member
    {
        $viewer = auth()->user();
        assert($viewer instanceof Member);

        return $viewer;
    }
}
