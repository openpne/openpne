<?php

namespace App\Features\Profile;

use App\Compat\RouteParityRegistry;
use App\Features\Profile\Queries\ShowProfile;
use App\Features\Profile\Serializers\ProfileSerializer;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Support\SurfaceResolver;
use App\Support\Visibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ProfileController extends Controller
{
    public function show(Request $request, Member $member, ShowProfile $query): View|InertiaResponse|RedirectResponse
    {
        /** @var Member|null $viewer */
        $viewer = $request->user();

        // A guest can only reach a web-public profile; otherwise send them to log in.
        if ($viewer === null && $member->profile_visibility !== Visibility::Open) {
            return redirect()->guest(route('login'));
        }

        $lang = $this->translationLang();
        $fields = $query($viewer, $member, $lang);
        abort_if($fields === null, 404); // owner blocks the viewer

        $isSelf = $viewer?->is($member) ?? false;

        return $this->respondWith($request, [
            SurfaceResolver::CLASSIC => fn () => view('member.show', [
                'owner' => $member,
                'fields' => $fields,
                'isSelf' => $isSelf,
                'lang' => $lang,
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('member/show', [
                'profile' => ProfileSerializer::page($member, $fields, $isSelf, $lang),
            ]),
        ]);
    }

    /** Translation lang code (OpenPNE/Doctrine I18n) for the current locale. */
    private function translationLang(): string
    {
        return app()->getLocale() === 'ja' ? 'ja_JP' : 'en';
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
