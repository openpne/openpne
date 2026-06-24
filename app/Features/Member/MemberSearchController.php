<?php

namespace App\Features\Member;

use App\Compat\RouteParityRegistry;
use App\Features\Member\Queries\SearchMembers;
use App\Features\Member\Serializers\MemberSearchSerializer;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Support\SurfaceResolver;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class MemberSearchController extends Controller
{
    public function search(Request $request, SearchMembers $query): View|InertiaResponse
    {
        $viewer = $this->viewer();
        $nameParam = $request->query('name', '');
        $name = is_string($nameParam) ? $nameParam : '';
        $profileFilters = $this->arrayParam($request, 'profile');
        $dateRanges = $this->arrayParam($request, 'date');
        $monthDayRanges = $this->arrayParam($request, 'monthday');
        $ageRange = $this->arrayParam($request, 'age');

        $members = $query($viewer, $name, $profileFilters, $dateRanges, $monthDayRanges, $ageRange);
        $lang = app()->getLocale() === 'ja' ? 'ja_JP' : 'en';
        $profiles = $query->searchableProfiles();
        $birthdayName = $query->birthdayProfileName();

        return $this->respondWith($request, [
            SurfaceResolver::CLASSIC => fn () => view('member.search', [
                'members' => $members,
                'profiles' => $profiles,
                'name' => $name,
                'filters' => $profileFilters,
                'dateRanges' => $dateRanges,
                'monthDayRanges' => $monthDayRanges,
                'ageRange' => $ageRange,
                'birthdayName' => $birthdayName,
                'lang' => $lang,
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('member/search', [
                'members' => MemberSearchSerializer::paginator($members),
                'profiles' => MemberSearchSerializer::formFields($profiles, $lang, $birthdayName),
                // Cast to object so an empty filter set serialises as {} (a keyed map), not [].
                'criteria' => [
                    'name' => $name,
                    'profile' => (object) $profileFilters,
                    'date' => (object) $dateRanges,
                    'monthday' => (object) $monthDayRanges,
                    'age' => (object) $ageRange,
                ],
            ]),
        ]);
    }

    /** @return array<int|string, mixed> */
    private function arrayParam(Request $request, string $key): array
    {
        $value = $request->query($key, []);

        return is_array($value) ? $value : [];
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

    private function viewer(): Member
    {
        $viewer = auth()->user();
        assert($viewer instanceof Member);

        return $viewer;
    }
}
