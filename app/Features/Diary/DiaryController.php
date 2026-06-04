<?php

namespace App\Features\Diary;

use App\Compat\RouteParityRegistry;
use App\Features\Diary\Actions\CreateDiary;
use App\Features\Diary\Actions\DeleteDiary;
use App\Features\Diary\Actions\UpdateDiary;
use App\Features\Diary\Exceptions\DiaryActionException;
use App\Features\Diary\Queries\ListDiaries;
use App\Features\Diary\Queries\ListFriendDiaries;
use App\Features\Diary\Queries\ListRecentDiaries;
use App\Features\Diary\Queries\SearchDiaries;
use App\Features\Diary\Queries\ShowDiary;
use App\Features\Diary\Serializers\DiarySerializer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Diary\StoreDiaryRequest;
use App\Http\Requests\Diary\UpdateDiaryRequest;
use App\Models\Diary;
use App\Models\Member;
use App\Support\SurfaceResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class DiaryController extends Controller
{
    public function listMember(Request $request, ListDiaries $query, ?Member $member = null): View|InertiaResponse
    {
        $viewer = $this->viewer();
        $owner = $member ?? $viewer;
        $diaries = $query($viewer, $owner);

        return $this->respondWith($request, [
            SurfaceResolver::CLASSIC => fn () => view('diary.list', [
                'owner' => $owner,
                'diaries' => $diaries,
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('diary/list', [
                'owner' => ['id' => $owner->getKey(), 'name' => $owner->name],
                'isOwner' => $viewer->is($owner),
                'diaries' => DiarySerializer::paginator($diaries),
            ]),
        ]);
    }

    public function listMemberArchive(Request $request, ListDiaries $query, Member $member): View|InertiaResponse
    {
        // Read the date off the route by name: a positional scalar would collide with the
        // `surface` default on the /m/* route. Segments are digit-constrained by the route.
        $day = $request->route('day');
        $period = ArchivePeriod::fromYearMonthDay(
            (int) $request->route('year'),
            (int) $request->route('month'),
            $day !== null ? (int) $day : null,
        );
        abort_if($period === null, 404);

        $viewer = $this->viewer();
        $diaries = $query($viewer, $member, period: $period);

        return $this->respondWith($request, [
            SurfaceResolver::CLASSIC => fn () => view('diary.list', [
                'owner' => $member,
                'diaries' => $diaries,
                'period' => $period->label,
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('diary/list', [
                'owner' => ['id' => $member->getKey(), 'name' => $member->name],
                'isOwner' => $viewer->is($member),
                'diaries' => DiarySerializer::paginator($diaries),
                'period' => $period->label,
            ]),
        ]);
    }

    public function list(Request $request, ListRecentDiaries $query): View|InertiaResponse
    {
        return $this->feed($request, 'recent', $query($this->viewer()));
    }

    public function listFriend(Request $request, ListFriendDiaries $query): View|InertiaResponse
    {
        return $this->feed($request, 'friends', $query($this->viewer()));
    }

    public function search(Request $request, SearchDiaries $query, ListRecentDiaries $recent): View|InertiaResponse
    {
        $keywordParam = $request->query('keyword', '');
        $keyword = is_string($keywordParam) ? $keywordParam : '';

        // OpenPNE 3 forwards an empty search to the list action — identical results, body id, and
        // pager URL (@diary_list). Delegate so /diary/search renders exactly what /diary/list does,
        // including pager links that point back at the list rather than at /diary/search.
        if (SearchDiaries::terms($keyword) === []) {
            return $this->feed(
                $request,
                'recent',
                $recent($this->viewer())->withPath(route('diary.list')),
                bodyIdRoute: 'diary.list',
            );
        }

        return $this->feed(
            $request,
            'search',
            $query($this->viewer(), $keyword),
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
        return $this->respondWith($request, [
            SurfaceResolver::CLASSIC => fn () => view('diary.feed', [
                'variant' => $variant,
                'keyword' => $keyword,
                'hasKeyword' => $hasKeyword,
                'diaries' => $diaries,
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('diary/feed', [
                'variant' => $variant,
                'keyword' => $keyword,
                'hasKeyword' => $hasKeyword,
                'diaries' => DiarySerializer::paginator($diaries),
            ]),
        ], bodyIdRoute: $bodyIdRoute);
    }

    public function show(Request $request, int $diary, ShowDiary $query): View|InertiaResponse
    {
        $viewer = $this->viewer();
        $found = $query($viewer, $diary);
        abort_if($found === null, 404);

        $comments = $found->comments()->with('member')->orderBy('number')->get();
        // Share the already-loaded diary so isDeletableBy() needs no per-comment query.
        $comments->each->setRelation('diary', $found);

        return $this->respondWith($request, [
            SurfaceResolver::CLASSIC => fn () => view('diary.show', [
                'diary' => $found,
                'comments' => $comments,
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('diary/show', [
                'diary' => DiarySerializer::detail($found),
                'comments' => DiarySerializer::comments($comments, $viewer),
            ]),
        ]);
    }

    public function new(Request $request): View|InertiaResponse
    {
        return $this->respondWith($request, [
            SurfaceResolver::CLASSIC => fn () => view('diary.new', [
                'visibilityOptions' => DiaryVisibility::options(),
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('diary/new'),
        ]);
    }

    public function store(StoreDiaryRequest $request, CreateDiary $action): RedirectResponse
    {
        $diary = $action($this->viewer(), $request->toData());

        return redirect()
            ->route(SurfaceResolver::redirectName($request, 'diary.show'), $diary)
            ->with('status', __('%Diary% posted.'));
    }

    public function edit(Request $request, Diary $diary): View|InertiaResponse
    {
        $viewer = $this->viewer();
        abort_unless($viewer->is($diary->member), 404);

        return $this->respondWith($request, [
            SurfaceResolver::CLASSIC => fn () => view('diary.edit', [
                'diary' => $diary,
                'visibilityOptions' => DiaryVisibility::options(),
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('diary/edit', [
                'diary' => DiarySerializer::detail($diary),
            ]),
        ]);
    }

    public function update(UpdateDiaryRequest $request, Diary $diary, UpdateDiary $action): RedirectResponse
    {
        try {
            $action($this->viewer(), $diary, $request->toData());
        } catch (DiaryActionException) {
            abort(404);
        }

        return redirect()
            ->route(SurfaceResolver::redirectName($request, 'diary.show'), $diary)
            ->with('status', __('%Diary% updated.'));
    }

    public function showDelete(Request $request, Diary $diary): View|InertiaResponse
    {
        $viewer = $this->viewer();
        abort_unless($viewer->is($diary->member), 404);

        return $this->respondWith($request, [
            SurfaceResolver::CLASSIC => fn () => view('diary.delete', [
                'diary' => $diary,
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('diary/delete', [
                'diary' => DiarySerializer::summary($diary),
            ]),
        ]);
    }

    public function delete(Request $request, Diary $diary, DeleteDiary $action): RedirectResponse
    {
        try {
            $action($this->viewer(), $diary);
        } catch (DiaryActionException) {
            abort(404);
        }

        return $this->redirectAfterSubmit($request, 'diary.list_member', status: __('%Diary% deleted.'));
    }

    private function redirectAfterSubmit(Request $request, string $canonicalName, ?string $status = null, ?string $error = null): RedirectResponse
    {
        $name = SurfaceResolver::redirectName($request, $canonicalName);
        $redirect = redirect()->route($name);
        if ($status !== null) {
            $redirect = $redirect->with('status', $status);
        }
        if ($error !== null) {
            $redirect = $redirect->with('error', $error);
        }

        return $redirect;
    }

    /**
     * @param  array{classic: callable(): (View|InertiaResponse), modern: callable(): (View|InertiaResponse)}  $responders
     * @param  string|null  $bodyIdRoute  Derive the Classic body id from this canonical route name
     *                                    instead of the current one (e.g. empty search renders the
     *                                    list page id). Still parity-derived, so no literal copy.
     */
    private function respondWith(Request $request, array $responders, ?string $bodyIdRoute = null): View|InertiaResponse
    {
        $response = $responders[SurfaceResolver::resolve($request, 'diary')]();

        // Classic body id is the OpenPNE 3 page_{module}_{action} hook, derived from the
        // route parity so it stays faithful to OpenPNE 3 (the controller holds no copy).
        // Canonicalize first: a /m/* route that fell back to Classic carries the modern
        // name (diary.modern.*), which the parity keys by canonical name.
        if ($response instanceof View) {
            $name = SurfaceResolver::canonicalName($bodyIdRoute ?? $request->route()->getName());
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
