<?php

namespace App\Features\Timeline;

use App\Compat\RouteParityRegistry;
use App\Features\Member\Serializers\MemberRefSerializer;
use App\Features\Notifications\ConsumeNotificationRows;
use App\Features\Notifications\NotificationTarget;
use App\Features\Timeline\Actions\CreateReply;
use App\Features\Timeline\Actions\CreateTimelinePost;
use App\Features\Timeline\Actions\DeleteTimelinePost;
use App\Features\Timeline\Queries\HomeFeed;
use App\Features\Timeline\Queries\MemberTimeline;
use App\Features\Timeline\Queries\MentionCandidates;
use App\Features\Timeline\Queries\RecentReplies;
use App\Features\Timeline\Queries\ShowTimelinePost;
use App\Features\Timeline\Queries\TagFeed;
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
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class TimelineController extends Controller
{
    use RespondsWithSurface;

    public function index(Request $request, HomeFeed $query, RecentReplies $recentReplies): View|InertiaResponse
    {
        $viewer = $this->viewer();
        $posts = $query($viewer);

        return $this->respondWith($request, 'timeline', [
            SurfaceResolver::CLASSIC => fn () => view('timeline.index', [
                'viewer' => $viewer,
                'posts' => $this->withInlineReplies($posts, $recentReplies),
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('timeline/index', [
                'viewerId' => $viewer->getKey(),
                'posts' => TimelinePostSerializer::paginator($posts, $viewer),
            ]),
        ]);
    }

    public function member(Request $request, MemberTimeline $query, Member $member, RecentReplies $recentReplies): View|InertiaResponse
    {
        $viewer = $this->viewer();
        $owner = $this->memberSubject($member);
        $posts = $query($viewer, $owner);

        return $this->respondWith($request, 'timeline', [
            SurfaceResolver::CLASSIC => fn () => view('timeline.member', [
                'owner' => $owner,
                'posts' => $this->withInlineReplies($posts, $recentReplies),
            ]),
            SurfaceResolver::MODERN => function () use ($owner, $viewer, $posts) {
                // The owner ref draws the chrome's scope avatar (Modern only, so Classic pays nothing).
                $owner->loadMissing('avatar.file');

                return Inertia::render('timeline/member', [
                    'owner' => MemberRefSerializer::ref($owner),
                    'isOwner' => $viewer->is($owner),
                    'viewerId' => $viewer->getKey(),
                    'posts' => TimelinePostSerializer::paginator($posts, $viewer),
                ]);
            },
        ]);
    }

    /**
     * One hashtag's feed. The tag arrives percent-decoded from the route and is normalized by the
     * query, so `#Tag` and `#ＴＡＧ` reach the same page; the normalized form is what the page shows,
     * since that is the topic the reader is actually on.
     */
    public function tag(Request $request, string $tag, TagFeed $query, RecentReplies $recentReplies): View|InertiaResponse
    {
        $viewer = $this->viewer();
        $normalized = HashtagParser::normalize($tag);
        $posts = $query($viewer, $tag);

        return $this->respondWith($request, 'timeline', [
            SurfaceResolver::CLASSIC => fn () => view('timeline.tag', [
                'viewer' => $viewer,
                'tag' => $normalized,
                'posts' => $this->withInlineReplies($posts, $recentReplies),
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('timeline/tag', [
                'viewerId' => $viewer->getKey(),
                'tag' => $normalized,
                'posts' => TimelinePostSerializer::paginator($posts, $viewer),
            ]),
        ]);
    }

    public function show(Request $request, int $timelinePost, ShowTimelinePost $query, LinkCardSync $linkCards, ConsumeNotificationRows $feedRows): View|InertiaResponse|RedirectResponse
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
        // about for the Classic localNav.
        $this->markLocalNavSubject($post->member);
        // Eager-load the replies' images, mentions and tags too: all three are read per reply when it
        // renders, so loading only replies.member would fire a query per reply for each (an images
        // load being empty, by the no-image contract, still costs the query).
        // `replies.linkCard.image` for the same reason the rest are here: without it a reply that
        // carries a card costs three queries of its own — the read trigger's freshness check, the
        // serializer's card, and that card's picture.
        $post->load([
            'member.avatar.file', 'replies.member.avatar.file', 'replies.images.file',
            'replies.mentions', 'replies.tags', 'replies.linkCard.image',
        ]);
        // The thread, not only its root: a reply is a body of its own, and this page is where one is
        // read — as a conversation page is for talk (LinkCardSync::ensureAll). Placed after the
        // reply-permalink redirect above, so a request that never renders queues nothing. Two calls
        // rather than one over a combined collection: `prepend` on a loaded relation mutates it in
        // place, which put the root into the page's own reply list.
        $linkCards->ensure($post);
        $linkCards->ensureAll($post->replies);
        // The whole thread, as the clearance above was read against it: a row about a reply is spent
        // by reading the page that reply is on, and this is that page.
        $feedRows->markTargetsRead(
            (int) $viewer->getKey(),
            NotificationTarget::timelinePost((int) $post->getKey()),
            ...$post->replies->map(fn (TimelinePost $reply): NotificationTarget => NotificationTarget::timelinePost((int) $reply->getKey()))->all(),
        );

        return $this->respondWith($request, 'timeline', [
            SurfaceResolver::CLASSIC => fn () => view('timeline.show', ['post' => $post]),
            SurfaceResolver::MODERN => fn () => Inertia::render('timeline/show', [
                'post' => TimelinePostSerializer::entry($post, $viewer),
                'replies' => array_map(fn (TimelinePost $reply): array => TimelinePostSerializer::entry($reply, $viewer), $post->replies->all()),
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

    /**
     * Members the compose form's @mention picker may offer for its search term. SNS-wide: the
     * timeline is one audience again now that group talk has replaced the community scope, and a
     * group-scoped roster is that feature's own endpoint (group.talk.mention_candidates).
     */
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

    public function storeReply(StoreReplyRequest $request, int $timelinePost, ShowTimelinePost $query, CreateReply $action): JsonResponse|RedirectResponse
    {
        $viewer = $this->viewer();
        // Replying requires viewing the thread; ShowTimelinePost re-centers to the root and applies
        // the same clearance/block gate, so a reply always attaches to a viewable top-level post.
        $root = $query($viewer, $timelinePost);
        abort_if($root === null, 404);
        $reply = $action($viewer, $root, $request->validated('body'), $request->toMentions());

        // The Classic row's inline form: the answer is the row to put where the form is, so the
        // page never has to guess what the server made of what was typed.
        if ($request->wantsJson()) {
            $reply->load(RecentReplies::WITH);

            return response()
                ->json(['html' => view('timeline._reply', ['reply' => $reply])->render()], 201)
                ->header('Cache-Control', 'private, no-store');
        }

        return redirect()
            ->route('timeline.show', ['timelinePost' => $root->getKey()])
            ->with('status', __('Reply posted.'));
    }

    /**
     * One root's whole reply list, as the rows the page would have drawn. Asked for when a reader
     * opens the earlier comments a row's tail left out; the thread page is the no-JS answer, so the
     * fragment carries no chrome and is never cached.
     */
    public function replies(int $timelinePost, ShowTimelinePost $query): Response
    {
        $post = $query($this->viewer(), $timelinePost);
        // A reply's id addresses no list of its own: ShowTimelinePost would re-center it to the
        // root, and answering with the root's list would leak which thread that id belongs to.
        abort_if($post === null || $post->getKey() !== $timelinePost, 404);
        $post->load(['replies.member.avatar.file', 'replies.mentions', 'replies.tags', 'replies.linkCard.image']);

        return response()
            ->view('timeline._replies', ['replies' => $post->replies])
            ->header('Cache-Control', 'private, no-store');
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

    /**
     * Attach each row's inline reply layer. Classic only — Modern's feed carries a reply count, not
     * the replies — so it hangs off the surface closure rather than the feed queries.
     *
     * @param  LengthAwarePaginator<int, TimelinePost>  $posts
     * @return LengthAwarePaginator<int, TimelinePost>
     */
    private function withInlineReplies(LengthAwarePaginator $posts, RecentReplies $recentReplies): LengthAwarePaginator
    {
        // items(), not getCollection(): only the former is on the contract the feed queries return.
        // Both hand back the same model instances, so the rows the view walks gain the relation.
        $recentReplies(new EloquentCollection($posts->items()));

        return $posts;
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
