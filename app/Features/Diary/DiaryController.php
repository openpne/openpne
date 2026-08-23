<?php

namespace App\Features\Diary;

use App\Compat\RouteParityRegistry;
use App\Features\Diary\Actions\CreateDiary;
use App\Features\Diary\Actions\DeleteDiary;
use App\Features\Diary\Actions\UpdateDiary;
use App\Features\Diary\Exceptions\DiaryActionException;
use App\Features\Diary\Queries\AdjacentDiaries;
use App\Features\Diary\Queries\HasWebPublicDiary;
use App\Features\Diary\Queries\ListDiaries;
use App\Features\Diary\Queries\ListFriendDiaries;
use App\Features\Diary\Queries\ListRecentDiaries;
use App\Features\Diary\Queries\MemberDiaryMonthlyCounts;
use App\Features\Diary\Queries\SearchDiaries;
use App\Features\Diary\Queries\ShowDiary;
use App\Features\Diary\Serializers\DiarySerializer;
use App\Features\Member\Serializers\MemberRefSerializer;
use App\Features\Notifications\ConsumeNotificationRows;
use App\Features\Notifications\NotificationTarget;
use App\Files\ImageEdit;
use App\Http\Controllers\Concerns\RespondsWithSurface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Diary\StoreDiaryRequest;
use App\Http\Requests\Diary\UpdateDiaryRequest;
use App\LinkCard\LinkCardSync;
use App\Models\Diary;
use App\Models\Member;
use App\Support\GuestLoginRedirect;
use App\Support\SurfaceResolver;
use App\Support\Visibility;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class DiaryController extends Controller
{
    use RespondsWithSurface;

    public function listMember(Request $request, ListDiaries $query, HasWebPublicDiary $publishes, ?Member $member = null): View|InertiaResponse|RedirectResponse
    {
        $viewer = $this->viewerOrGuest();
        if ($viewer === null && ($member === null || ! $publishes($member))) {
            // OpenPNE 3 executeListMember: a guest reaches an author's archive only when that
            // author has a web-public entry, and the id-less "my archive" needs a viewer at all.
            return GuestLoginRedirect::response();
        }

        $owner = $this->memberSubject($member);
        // Modern narrows the archive by keyword; Classic ignores it (OpenPNE 3 has no such filter),
        // so each surface runs its own query inside its closure — Classic keyword-free, unchanged.
        $keyword = $this->keywordParam($request);

        return $this->respondWith($request, 'diary', [
            SurfaceResolver::CLASSIC => fn () => $this->classicScreen('diary.list', [
                'owner' => $owner,
                'diaries' => $query($viewer, $owner),
            ]),
            SurfaceResolver::MODERN => function () use ($query, $owner, $viewer, $keyword) {
                // Modern-only: eager-load the thumbnail sources here, not in the query, so Classic
                // pays nothing. loadMissing forwards through the paginator to its collection.
                $diaries = $query($viewer, $owner, keyword: $keyword);
                $diaries->loadMissing('images.file');
                // The owner ref draws the chrome's scope avatar (Modern only, so Classic pays nothing).
                $owner->loadMissing('avatar.file');

                return Inertia::render('diary/list', [
                    'owner' => MemberRefSerializer::ref($owner),
                    'isOwner' => $viewer?->is($owner) ?? false,
                    'diaries' => DiarySerializer::paginator($diaries),
                    'monthlyCounts' => (new MemberDiaryMonthlyCounts)($viewer, $owner, $keyword),
                    'keyword' => $keyword,
                    'archive' => null,
                ]);
            },
        ]);
    }

    public function listMemberArchive(Request $request, ListDiaries $query, HasWebPublicDiary $publishes, Member $member): View|InertiaResponse|RedirectResponse
    {
        $viewer = $this->viewerOrGuest();
        // Guest eligibility is the author's, not the window's: an empty month on an author who
        // publishes still renders (OpenPNE 3 hasOpenDiary runs before the date is even read).
        if ($viewer === null && ! $publishes($member)) {
            return GuestLoginRedirect::response();
        }

        // Read the date off the route by name: a positional scalar would collide with the
        // `surface` default on the /m/* route. Segments are digit-constrained by the route.
        $day = $request->route('day');
        $period = ArchivePeriod::fromYearMonthDay(
            (int) $request->route('year'),
            (int) $request->route('month'),
            $day !== null ? (int) $day : null,
        );
        abort_if($period === null, 404);

        $member = $this->memberSubject($member);
        $keyword = $this->keywordParam($request);

        return $this->respondWith($request, 'diary', [
            SurfaceResolver::CLASSIC => fn () => $this->classicScreen('diary.list', [
                'owner' => $member,
                'diaries' => $query($viewer, $member, period: $period),
                'period' => $period->label,
                'archiveStart' => $period->start,
            ]),
            SurfaceResolver::MODERN => function () use ($request, $query, $member, $viewer, $period, $keyword) {
                // period × keyword are orthogonal: the month range and the term filter stack, so the
                // grid becomes a map of when this member wrote about the term.
                $diaries = $query($viewer, $member, period: $period, keyword: $keyword);
                $diaries->loadMissing('images.file');
                $member->loadMissing('avatar.file');

                return Inertia::render('diary/list', [
                    'owner' => MemberRefSerializer::ref($member),
                    'isOwner' => $viewer?->is($member) ?? false,
                    'diaries' => DiarySerializer::paginator($diaries),
                    'period' => $period->label,
                    'monthlyCounts' => (new MemberDiaryMonthlyCounts)($viewer, $member, $keyword),
                    'keyword' => $keyword,
                    // A day archive still highlights its month cell, so only year+month are sent.
                    'archive' => ['year' => (int) $request->route('year'), 'month' => (int) $request->route('month')],
                ]);
            },
        ]);
    }

    public function list(Request $request, ListRecentDiaries $query): View|InertiaResponse
    {
        return $this->feed($request, 'recent', $query($this->viewerOrGuest()));
    }

    public function listFriend(Request $request, ListFriendDiaries $query): View|InertiaResponse
    {
        return $this->feed($request, 'friends', $query($this->viewer()));
    }

    public function search(Request $request, SearchDiaries $query, ListRecentDiaries $recent): View|InertiaResponse
    {
        $viewer = $this->viewerOrGuest();
        $keyword = $this->keywordParam($request);

        // OpenPNE 3 forwards an empty search to the list action — identical results, body id, and
        // pager URL (@diary_list). Delegate so /diary/search renders exactly what /diary/list does,
        // including pager links that point back at the list rather than at /diary/search.
        if (SearchDiaries::terms($keyword) === []) {
            return $this->feed(
                $request,
                'recent',
                $recent($viewer)->withPath(route('diary.list')),
                bodyIdRoute: 'diary.list',
            );
        }

        return $this->feed(
            $request,
            'search',
            $query($viewer, $keyword),
            keyword: $keyword,
            hasKeyword: true,
        );
    }

    /**
     * OpenPNE 3 listSuccess.php: the all-member feed and search share one template carrying the
     * search form; the friend feed drops it. The variant drives the heading and the form.
     *
     * @param  'recent'|'friends'|'search'  $variant
     * @param  LengthAwarePaginator<int, Diary>  $diaries
     */
    private function feed(Request $request, string $variant, LengthAwarePaginator $diaries, string $keyword = '', bool $hasKeyword = false, ?string $bodyIdRoute = null): View|InertiaResponse
    {
        return $this->respondWith($request, 'diary', [
            SurfaceResolver::CLASSIC => fn () => $this->classicScreen('diary.feed', [
                'variant' => $variant,
                'keyword' => $keyword,
                'hasKeyword' => $hasKeyword,
                'diaries' => $diaries,
            ]),
            SurfaceResolver::MODERN => function () use ($variant, $keyword, $hasKeyword, $diaries) {
                $diaries->loadMissing('images.file');

                return Inertia::render('diary/feed', [
                    'variant' => $variant,
                    'keyword' => $keyword,
                    'hasKeyword' => $hasKeyword,
                    'diaries' => DiarySerializer::paginator($diaries),
                ]);
            },
        ], bodyIdRoute: $bodyIdRoute);
    }

    public function show(Request $request, int $diary, ShowDiary $query, AdjacentDiaries $adjacent, LinkCardSync $linkCards, ConsumeNotificationRows $feedRows): View|InertiaResponse|RedirectResponse
    {
        $viewer = $this->viewerOrGuest();
        $found = $query($viewer, $diary);
        if ($found === null) {
            // OpenPNE 3 sends a guest to login for both a missing entry and one that is not
            // web-public, so the response never tells a signed-out visitor which it was.
            if ($viewer === null) {
                return GuestLoginRedirect::response();
            }

            abort(404);
        }
        // ShowDiary already gated the block (null → 404 above); record the author for the
        // Classic friend localNav when viewing someone else's diary.
        $this->markLocalNavSubject($found->member);
        // After the access decision, and only here: the feeds render many entries, and asking on
        // each would queue a page's worth of jobs for someone scrolling past.
        $linkCards->ensure($found);
        if ($viewer !== null) {
            $feedRows->markTargetsRead((int) $viewer->getKey(), NotificationTarget::diary((int) $found->getKey()));
        }

        // Same viewer-scoped adjacency for both surfaces; hoisted so Modern gets it too.
        ['older' => $older, 'newer' => $newer] = $adjacent($viewer, $found);

        return $this->respondWith($request, 'diary', [
            SurfaceResolver::CLASSIC => function () use ($request, $found, $older, $newer, $linkCards) {
                $thread = DiaryCommentThread::paginate(
                    $found, $request->query('size'), $request->query('order'), $request->query('page'),
                );
                // Share the already-loaded diary so isDeletableBy() needs no per-comment query.
                $thread->comments->each->setRelation('diary', $found);
                // The comments this page renders, as the diary was above: a comment is a body of its
                // own, and this is the page it is read on (LinkCardSync::ensureAll).
                $linkCards->ensureAll($thread->comments);

                // Classic keeps OpenPNE 3's previous(older)/next(newer) template vars for parity.
                return $this->classicScreen('diary.show', [
                    'diary' => $found,
                    'thread' => $thread,
                    'previousDiary' => $older,
                    'nextDiary' => $newer,
                ]);
            },
            SurfaceResolver::MODERN => function () use ($found, $viewer, $older, $newer, $linkCards) {
                $comments = $found->comments()->with(['member.avatar.file', 'images.file', 'linkCard.image'])->orderBy('number')->get();
                $comments->each->setRelation('diary', $found);
                $linkCards->ensureAll($comments);

                return Inertia::render('diary/show', [
                    'diary' => DiarySerializer::detail($found),
                    'comments' => DiarySerializer::comments($comments, $viewer),
                    'older' => DiarySerializer::neighbor($older),
                    'newer' => DiarySerializer::neighbor($newer),
                ]);
            },
        ]);
    }

    public function new(Request $request): View|InertiaResponse
    {
        $default = DiaryVisibility::defaultFor($this->viewer());

        return $this->respondWith($request, 'diary', [
            SurfaceResolver::CLASSIC => fn () => view('diary.new', [
                'visibilityOptions' => DiaryVisibility::options(),
                'defaultVisibility' => $default,
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('diary/new', [
                'defaultVisibility' => (string) $default->value,
                'visibilityOptions' => self::modernVisibilityOptions(DiaryVisibility::options()),
                'composeEditor' => $this->viewer()->composeEditor()->value,
            ]),
        ]);
    }

    public function store(StoreDiaryRequest $request, CreateDiary $action): RedirectResponse
    {
        $diary = $action($this->viewer(), $request->toData(), $request->file('images', []));

        return redirect()
            ->route('diary.show', $diary)
            ->with('status', __('%Diary% posted.'));
    }

    public function edit(Request $request, Diary $diary): View|InertiaResponse
    {
        $viewer = $this->viewer();
        abort_unless($viewer->is($diary->member), 404);

        // Render the current images (and let the Modern serializer read them) without an N+1.
        $diary->load('images.file');

        // Editing keeps this entry's own audience offered even where the picker no longer offers
        // that tier, so saving an untouched form re-posts what is stored instead of widening it.
        $options = DiaryVisibility::options($diary->visibility);

        return $this->respondWith($request, 'diary', [
            SurfaceResolver::CLASSIC => fn () => view('diary.edit', [
                'diary' => $diary,
                'visibilityOptions' => $options,
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('diary/edit', [
                'diary' => DiarySerializer::detail($diary),
                'visibility' => (string) $diary->visibility->value,
                'visibilityOptions' => self::modernVisibilityOptions($options),
                'composeEditor' => $viewer->composeEditor()->value,
            ]),
        ]);
    }

    public function update(UpdateDiaryRequest $request, Diary $diary, UpdateDiary $action): RedirectResponse
    {
        try {
            $action(
                $this->viewer(),
                $diary,
                $request->toData(),
                ImageEdit::fromRequest($request),
            );
        } catch (DiaryActionException) {
            abort(404);
        }

        return redirect()
            ->route('diary.show', $diary)
            ->with('status', __('%Diary% updated.'));
    }

    public function showDelete(Request $request, Diary $diary): View|RedirectResponse
    {
        $viewer = $this->viewer();
        abort_unless($viewer->is($diary->member), 404);

        // Modern confirms delete inline (Radix AlertDialog) — send a Modern viewer back to the diary.
        if (SurfaceResolver::resolve($request, 'diary') === SurfaceResolver::MODERN) {
            return redirect()->route('diary.show', $diary);
        }

        return $this->classic('diary.delete', ['diary' => $diary]);
    }

    public function delete(Request $request, Diary $diary, DeleteDiary $action): RedirectResponse
    {
        try {
            $action($this->viewer(), $diary);
        } catch (DiaryActionException) {
            abort(404);
        }

        return $this->redirectAfterSubmit('diary.list_member', status: __('%Diary% deleted.'));
    }

    /**
     * Drive the Modern selects from the same audiences Classic renders, so neither form can submit
     * an option it does not visibly offer. Labels stay translation keys — the client runs t().
     *
     * @param  list<Visibility>  $options
     * @return list<array{value: string, label: string}>
     */
    private static function modernVisibilityOptions(array $options): array
    {
        return array_map(
            fn (Visibility $option): array => ['value' => (string) $option->value, 'label' => $option->label()],
            $options,
        );
    }

    /** Read the ?keyword= query param defensively — an array-shaped param arrives as a non-string. */
    private function keywordParam(Request $request): string
    {
        $keyword = $request->query('keyword', '');

        return is_string($keyword) ? $keyword : '';
    }

    /**
     * A guest-reachable Classic diary screen. OpenPNE 3 flipped the diary actions to `is_secure`
     * only for an authenticated viewer (opDiaryPluginActions::postExecute), so a signed-out one
     * gets the pre-login body class — and with it the skin rules and footer slot keyed off it.
     */
    private function classicScreen(string $view, array $data): View
    {
        return view($view, $data)->with('pageClass', auth()->check() ? 'secure_page' : 'insecure_page');
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
