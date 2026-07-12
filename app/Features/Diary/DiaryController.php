<?php

namespace App\Features\Diary;

use App\Compat\RouteParityRegistry;
use App\Features\Diary\Actions\CreateDiary;
use App\Features\Diary\Actions\DeleteDiary;
use App\Features\Diary\Actions\UpdateDiary;
use App\Features\Diary\Exceptions\DiaryActionException;
use App\Features\Diary\Queries\AdjacentDiaries;
use App\Features\Diary\Queries\ListDiaries;
use App\Features\Diary\Queries\ListFriendDiaries;
use App\Features\Diary\Queries\ListRecentDiaries;
use App\Features\Diary\Queries\SearchDiaries;
use App\Features\Diary\Queries\ShowDiary;
use App\Features\Diary\Serializers\DiarySerializer;
use App\Files\ImageEdit;
use App\Http\Controllers\Concerns\RespondsWithSurface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Diary\StoreDiaryRequest;
use App\Http\Requests\Diary\UpdateDiaryRequest;
use App\Models\Diary;
use App\Models\Member;
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

    public function listMember(Request $request, ListDiaries $query, ?Member $member = null): View|InertiaResponse
    {
        $viewer = $this->viewer();
        $owner = $this->memberSubject($member);
        $diaries = $query($viewer, $owner);

        return $this->respondWith($request, 'diary', [
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
        $member = $this->memberSubject($member);
        $diaries = $query($viewer, $member, period: $period);

        return $this->respondWith($request, 'diary', [
            SurfaceResolver::CLASSIC => fn () => view('diary.list', [
                'owner' => $member,
                'diaries' => $diaries,
                'period' => $period->label,
                'archiveStart' => $period->start,
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
        return $this->respondWith($request, 'diary', [
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

    public function show(Request $request, int $diary, ShowDiary $query, AdjacentDiaries $adjacent): View|InertiaResponse
    {
        $viewer = $this->viewer();
        $found = $query($viewer, $diary);
        abort_if($found === null, 404);
        // ShowDiary already gated the block (null → 404 above); record the author for the
        // Classic friend localNav when viewing someone else's diary.
        $this->markLocalNavSubject($found->member);

        // Same viewer-scoped adjacency for both surfaces; hoisted so Modern gets it too.
        ['previous' => $previous, 'next' => $next] = $adjacent($viewer, $found);

        return $this->respondWith($request, 'diary', [
            SurfaceResolver::CLASSIC => function () use ($request, $found, $previous, $next) {
                $thread = DiaryCommentThread::paginate(
                    $found, $request->query('size'), $request->query('order'), $request->query('page'),
                );
                // Share the already-loaded diary so isDeletableBy() needs no per-comment query.
                $thread->comments->each->setRelation('diary', $found);

                return view('diary.show', [
                    'diary' => $found,
                    'thread' => $thread,
                    'previousDiary' => $previous,
                    'nextDiary' => $next,
                ]);
            },
            SurfaceResolver::MODERN => function () use ($found, $viewer, $previous, $next) {
                $comments = $found->comments()->with(['member', 'images.file'])->orderBy('number')->get();
                $comments->each->setRelation('diary', $found);

                return Inertia::render('diary/show', [
                    'diary' => DiarySerializer::detail($found),
                    'comments' => DiarySerializer::comments($comments, $viewer),
                    'previous' => DiarySerializer::neighbor($previous),
                    'next' => DiarySerializer::neighbor($next),
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
                // Drive the Modern select from the same selectable audiences as Classic, so it
                // can never submit an option (e.g. Open) it does not visibly render.
                'visibilityOptions' => array_map(
                    fn (Visibility $option): array => ['value' => (string) $option->value, 'label' => $option->label()],
                    DiaryVisibility::options(),
                ),
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

        return $this->respondWith($request, 'diary', [
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
