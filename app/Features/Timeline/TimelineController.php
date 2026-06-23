<?php

namespace App\Features\Timeline;

use App\Compat\RouteParityRegistry;
use App\Features\Timeline\Queries\MemberTimeline;
use App\Features\Timeline\Queries\ShowTimelinePost;
use App\Features\Timeline\Serializers\TimelinePostSerializer;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Support\SurfaceResolver;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class TimelineController extends Controller
{
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
