<?php

namespace App\Features\Diary;

use App\Compat\RouteParityRegistry;
use App\Features\Diary\Actions\CreateDiary;
use App\Features\Diary\Actions\DeleteDiary;
use App\Features\Diary\Actions\UpdateDiary;
use App\Features\Diary\Exceptions\DiaryActionException;
use App\Features\Diary\Queries\ListDiaries;
use App\Features\Diary\Queries\ShowDiary;
use App\Features\Diary\Serializers\DiarySerializer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Diary\StoreDiaryRequest;
use App\Http\Requests\Diary\UpdateDiaryRequest;
use App\Models\Diary;
use App\Models\Member;
use App\Support\SurfaceResolver;
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

    public function show(Request $request, int $diary, ShowDiary $query): View|InertiaResponse
    {
        $found = $query($this->viewer(), $diary);
        abort_if($found === null, 404);

        return $this->respondWith($request, [
            SurfaceResolver::CLASSIC => function () use ($found) {
                $comments = $found->comments()->with('member')->orderBy('number')->get();
                // Share the already-loaded diary so isDeletableBy() needs no per-comment query.
                $comments->each->setRelation('diary', $found);

                return view('diary.show', ['diary' => $found, 'comments' => $comments]);
            },
            SurfaceResolver::MODERN => fn () => Inertia::render('diary/show', [
                'diary' => DiarySerializer::detail($found),
            ]),
        ]);
    }

    public function new(Request $request): View|InertiaResponse
    {
        return $this->respondWith($request, [
            SurfaceResolver::CLASSIC => fn () => view('diary.new'),
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
     */
    private function respondWith(Request $request, array $responders): View|InertiaResponse
    {
        $response = $responders[SurfaceResolver::resolve($request, 'diary')]();

        // Classic body id is the OpenPNE 3 page_{module}_{action} hook, derived from the
        // route parity so it stays faithful to OpenPNE 3 (the controller holds no copy).
        // Canonicalize first: a /m/* route that fell back to Classic carries the modern
        // name (diary.modern.*), which the parity keys by canonical name.
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
