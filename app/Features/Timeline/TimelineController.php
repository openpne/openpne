<?php

namespace App\Features\Timeline;

use App\Compat\RouteParityRegistry;
use App\Features\Community\Serializers\CommunitySerializer;
use App\Features\Member\Serializers\MemberRefSerializer;
use App\Features\Timeline\Actions\CreateReply;
use App\Features\Timeline\Actions\CreateTimelinePost;
use App\Features\Timeline\Actions\DeleteTimelinePost;
use App\Features\Timeline\Queries\CommunityTimeline;
use App\Features\Timeline\Queries\HomeFeed;
use App\Features\Timeline\Queries\MemberTimeline;
use App\Features\Timeline\Queries\MentionCandidates;
use App\Features\Timeline\Queries\ShowTimelinePost;
use App\Features\Timeline\Queries\TagFeed;
use App\Features\Timeline\Serializers\TimelinePostSerializer;
use App\Http\Controllers\Concerns\RespondsWithSurface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Timeline\StoreCommunityTimelinePostRequest;
use App\Http\Requests\Timeline\StoreReplyRequest;
use App\Http\Requests\Timeline\StoreTimelinePostRequest;
use App\LinkCard\LinkCardSync;
use App\Models\Community;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Feature;
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

    /**
     * One community's timeline. The community carries the localNav, as OpenPNE 3 did by setting
     * sf_nav_type to `community` on this page — the reader is inside a community here, not on
     * someone's profile.
     */
    public function community(Request $request, Community $community, CommunityTimeline $query): View|InertiaResponse
    {
        $viewer = $this->viewer();
        abort_unless(CommunityTimelineAccess::canViewTimeline($community, $viewer), 404);

        $posts = $query($viewer, $community);
        $this->markLocalNavCommunity($community);

        return $this->respondWith($request, 'timeline', [
            SurfaceResolver::CLASSIC => fn () => view('timeline.community', [
                'community' => $community,
                'viewer' => $viewer,
                'posts' => $posts,
                'canPost' => CommunityTimelineAccess::canPost($community, $viewer),
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('timeline/community', [
                'community' => CommunitySerializer::summary($community),
                'viewerId' => $viewer->getKey(),
                'canPost' => CommunityTimelineAccess::canPost($community, $viewer),
                'posts' => TimelinePostSerializer::paginator($posts),
            ]),
        ]);
    }

    /**
     * The compose page for a community. Classic needs it as much as Modern: the box's form ships
     * hidden and is swapped in by script, so without a real page behind the fallback link there is
     * no way to post at all with the script off.
     */
    public function newCommunity(Request $request, Community $community): View|InertiaResponse
    {
        abort_unless(CommunityTimelineAccess::canPost($community, $this->viewer()), 404);
        $this->markLocalNavCommunity($community);

        return $this->respondWith($request, 'timeline', [
            SurfaceResolver::CLASSIC => fn () => view('timeline.new', [
                'community' => $community,
                'visibilityOptions' => [],
                'defaultVisibility' => Visibility::Members,
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('timeline/community-new', [
                'defaultVisibility' => (string) Visibility::Members->value,
                'visibilityOptions' => [],
                'community' => CommunitySerializer::summary($community),
            ]),
        ]);
    }

    public function storeCommunity(StoreCommunityTimelinePostRequest $request, Community $community, CreateTimelinePost $action): RedirectResponse
    {
        $viewer = $this->viewer();
        // Reading an everyone-readable community does not admit someone to its conversation; the
        // action refuses too, which is what protects the reply route that has no such check here.
        abort_unless(CommunityTimelineAccess::canPost($community, $viewer), 404);

        $action($viewer, $request->toData(), $request->file('image'), $community);

        return redirect()
            ->route('community.timeline', ['community' => $community->getKey()])
            ->with('status', __('Posted.'));
    }

    /**
     * One hashtag's posts. The tag arrives as it was typed into the URL, so it is normalized the way
     * the parser normalized what is stored — the page for `#Tag` and the page for `#tag` are one page.
     */
    public function tag(Request $request, string $tag, TagFeed $query): View|InertiaResponse
    {
        $viewer = $this->viewer();
        $tag = HashtagParser::normalize($tag);
        $posts = $query($viewer, $tag);

        return $this->respondWith($request, 'timeline', [
            SurfaceResolver::CLASSIC => fn () => view('timeline.tag', [
                'tag' => $tag,
                'posts' => $posts,
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('timeline/tag', [
                'tag' => $tag,
                'viewerId' => $viewer->getKey(),
                'posts' => TimelinePostSerializer::paginator($posts),
            ]),
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

        // ShowTimelinePost already gated the block (null → 404 above); record what the page is
        // about for the Classic localNav. A community post is about its community, not its author —
        // the reader arrived from inside the community and the nav should keep them there.
        if ($post->community !== null) {
            $this->markLocalNavCommunity($post->community);
        } else {
            $this->markLocalNavSubject($post->member);
        }
        // Eager-load the replies' images, mentions and tags too: all three are read per reply when it
        // renders, so loading only replies.member would fire a query per reply for each (an images
        // load being empty, by the no-image contract, still costs the query).
        $post->load(['member.avatar.file', 'replies.member.avatar.file', 'replies.images.file', 'replies.mentions', 'replies.tags']);
        // The thread root only. Replies share this table but render as a thread underneath, where a
        // stack of cards would read as noise — and asking per reply would queue a job each. Placed
        // after the reply-permalink redirect above, so a request that never renders queues nothing.
        $linkCards->ensure($post);

        // Reading a community thread does not admit someone to it: an everyone-readable community
        // is open to any member, but only its own may reply.
        $canReply = $post->community === null || CommunityTimelineAccess::canPost($post->community, $viewer);

        return $this->respondWith($request, 'timeline', [
            SurfaceResolver::CLASSIC => fn () => view('timeline.show', [
                'post' => $post,
                'viewer' => $viewer,
                'canReply' => $canReply,
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('timeline/show', [
                'post' => TimelinePostSerializer::entry($post),
                'replies' => array_map([TimelinePostSerializer::class, 'entry'], $post->replies->all()),
                'viewerId' => $viewer->getKey(),
                'canReply' => $canReply,
                // The Modern chrome reads props, not the request attribute markLocalNavCommunity
                // sets, so the community has to travel for the crumb and scope to follow the thread.
                'community' => $post->community === null ? null : CommunitySerializer::summary($post->community),
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

    /**
     * Members the compose form's @mention picker may offer for its search term. Composing into a
     * community narrows the offer to its members, so the picker and the submit agree — and the
     * caller must be one of them, or this would hand a members-only community's roster to an
     * outsider a name at a time.
     */
    public function mentionCandidates(Request $request, MentionCandidates $query): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'community' => ['nullable', 'integer'],
        ]);

        $viewer = $this->viewer();
        $community = null;

        if ($request->filled('community')) {
            // The unit gate is checked here, not left to canPost: this endpoint is gated on the
            // timeline unit alone, and the roster is exactly what switching the community unit off
            // is meant to take away.
            $community = Feature::Community->enabled() ? Community::find($request->integer('community')) : null;
            abort_unless($community !== null && CommunityTimelineAccess::canPost($community, $viewer), 404);
        }

        $candidates = $query($viewer, $request->string('q')->value(), $community);

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
        // The action refuses too, but it throws; the reply route is the SNS-wide one, so the gate
        // has to be here for an outsider on an everyone-readable community to get a 404, not a 500.
        abort_if($root->community !== null && ! CommunityTimelineAccess::canPost($root->community, $viewer), 404);

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
        $communityId = $timelinePost->community_id;
        $action($timelinePost);

        if ($parentId !== null) {
            return redirect()
                ->route('timeline.show', ['timelinePost' => $parentId])
                ->with('status', __('Reply deleted.'));
        }

        // A community post came from the community's timeline, not the author's; sending them to
        // their own would leave the page they were on for one they were not.
        if ($communityId !== null) {
            return redirect()
                ->route('community.timeline', ['community' => $communityId])
                ->with('status', __('Post deleted.'));
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
