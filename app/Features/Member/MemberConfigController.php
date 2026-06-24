<?php

namespace App\Features\Member;

use App\Compat\RouteParityRegistry;
use App\Features\Diary\DiaryVisibility;
use App\Features\Member\Serializers\MemberConfigSerializer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UpdateDiaryDefaultRequest;
use App\Http\Requests\Member\UpdatePreferredSurfaceRequest;
use App\Models\Member;
use App\Support\PreferenceKey;
use App\Support\Surface;
use App\Support\SurfaceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The member's own settings page (OpenPNE 3 member/config), Classic + Modern. Each section is its
 * own submit so saving one never rewrites another — in particular, the diary section shows the
 * read-time clamped default (DiaryVisibility::defaultFor), which must not be written back on an
 * unrelated change (it would collapse a stored Open once web-public is off).
 */
class MemberConfigController extends Controller
{
    public function show(Request $request): View|InertiaResponse|RedirectResponse
    {
        // OpenPNE 3 access-block lived at /member/config?category=accessBlock; preserve that URL by
        // resolving just that category to the canonical Block list.
        if ($request->query('category') === 'accessBlock') {
            return redirect()->route('block.list');
        }

        $viewer = $this->viewer();

        return $this->respondWith($request, [
            SurfaceResolver::CLASSIC => fn () => view('member.config', [
                'diaryDefault' => DiaryVisibility::defaultFor($viewer),
                'diaryOptions' => DiaryVisibility::options(),
                'locale' => app()->getLocale(),
                'preferredSurface' => $viewer->preferredSurface(),
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('member/config', [
                'form' => MemberConfigSerializer::form($viewer),
            ]),
        ]);
    }

    public function updateDiary(UpdateDiaryDefaultRequest $request): RedirectResponse
    {
        $value = PreferenceKey::DiaryDefaultVisibility->coerce($request->validated('diary_default_visibility'));
        $this->viewer()->setPreference(PreferenceKey::DiaryDefaultVisibility, $value);

        return redirect()
            ->route(SurfaceResolver::redirectName($request, 'member.config'))
            ->with('status', __('Settings updated.'));
    }

    public function updateSurface(UpdatePreferredSurfaceRequest $request): Response
    {
        $value = $request->validated('preferred_surface');
        $surface = $value === null ? null : Surface::from($value);
        $viewer = $this->viewer();

        if ($surface === null) {
            $viewer->resetPreferredSurface();
        } else {
            $viewer->setPreferredSurface($surface);
        }

        // Land on the chosen surface's own config page so the whole shell re-renders there. An
        // explicit /m/* URL is top of SurfaceResolver's order, so a Classic (or reset) choice MUST
        // leave /m/* for the canonical route, or the page would stay Modern. A reset then follows
        // the session/tenant fallback, which the canonical route resolves correctly.
        $target = $surface === Surface::Modern ? route('member.modern.config') : route('member.config');
        $request->session()->flash('status', __('Settings updated.'));

        return $request->hasHeader('X-Inertia') ? Inertia::location($target) : redirect($target);
    }

    private function viewer(): Member
    {
        $viewer = auth()->user();
        assert($viewer instanceof Member);

        return $viewer;
    }

    /**
     * @param  array{classic: callable(): (View|InertiaResponse), modern: callable(): (View|InertiaResponse)}  $responders
     */
    private function respondWith(Request $request, array $responders): View|InertiaResponse
    {
        $response = $responders[SurfaceResolver::resolve($request, 'member')]();

        if ($response instanceof View) {
            $name = SurfaceResolver::canonicalName($request->route()->getName());
            $response->with('pageId', RouteParityRegistry::bodyId($name));
        }

        return $response;
    }
}
