<?php

namespace App\Features\Profile;

use App\Features\Profile\Actions\SaveMemberProfile;
use App\Features\Profile\Queries\EditProfileFields;
use App\Features\Profile\Queries\ShowProfile;
use App\Features\Profile\Queries\VisibleAge;
use App\Features\Profile\Serializers\ProfileFormSerializer;
use App\Features\Profile\Serializers\ProfileSerializer;
use App\Http\Controllers\Concerns\RespondsWithSurface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Models\Member;
use App\Services\GadgetService;
use App\Support\SurfaceResolver;
use App\Support\Visibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ProfileController extends Controller
{
    use RespondsWithSurface;

    public function show(Request $request, Member $member, ShowProfile $query, GadgetService $gadgets, VisibleAge $visibleAge): View|InertiaResponse|RedirectResponse
    {
        /** @var Member|null $viewer */
        $viewer = $request->user();

        // A guest can only reach a web-public profile; otherwise send them to log in.
        if ($viewer === null && $member->profile_visibility !== Visibility::Open) {
            return redirect()->guest(route('login'));
        }

        $this->memberSubject($member); // 404 when the owner has blocked the viewer

        $lang = $this->translationLang();
        $fields = $query($viewer, $member, $lang);
        abort_if($fields === null, 404); // defense in depth: ShowProfile also nulls on block

        $isSelf = $viewer?->is($member) ?? false;
        // The gadget-driven Classic surface re-resolves age in the ProfileListBox component; this
        // covers the Modern surface and the no-gadget fixed box.
        $age = $visibleAge($viewer, $member);

        return $this->respondWith($request, 'member', [
            SurfaceResolver::CLASSIC => fn () => view('member.show', [
                'owner' => $member,
                'fields' => $fields,
                'age' => $age,
                'isSelf' => $isSelf,
                'lang' => $lang,
                'zones' => $gadgets->zones('profile', subject: $member, viewer: $viewer),
                'layout' => $gadgets->layoutLetter('profile'),
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('member/show', [
                'profile' => ProfileSerializer::page($member, $fields, $isSelf, $lang, $age),
            ]),
        ]);
    }

    public function edit(Request $request, EditProfileFields $query): View|InertiaResponse
    {
        $viewer = $this->viewer();
        $lang = $this->translationLang();
        $fields = $query($viewer);

        return $this->respondWith($request, 'member', [
            SurfaceResolver::CLASSIC => fn () => view('member.edit-profile', [
                'member' => $viewer,
                'fields' => $fields,
                'lang' => $lang,
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('member/edit-profile', [
                'form' => ProfileFormSerializer::form($viewer->name, $fields, $lang),
            ]),
        ]);
    }

    public function update(UpdateProfileRequest $request, SaveMemberProfile $action): RedirectResponse
    {
        $action($this->viewer(), $request->toData());

        return redirect()
            ->route(SurfaceResolver::redirectName($request, 'member.profile.edit'))
            ->with('status', __('Profile updated.'));
    }

    /** Translation lang code (OpenPNE/Doctrine I18n) for the current locale. */
    private function translationLang(): string
    {
        return app()->getLocale() === 'ja' ? 'ja_JP' : 'en';
    }

    private function viewer(): Member
    {
        $viewer = auth()->user();
        assert($viewer instanceof Member);

        return $viewer;
    }
}
