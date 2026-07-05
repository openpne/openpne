<?php

namespace App\Features\Profile;

use App\Features\Block\BlockLookup;
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
use App\Models\Profile;
use App\Services\GadgetService;
use App\Support\PreferenceKey;
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

        // Entry point for a friend request (OpenPNE 3 profile parity). memberSubject above only
        // 404s the owner→viewer block; a viewer who blocks the owner still reaches this page, and
        // the friend-link form rejects any block direction — so hide the entry for both (null).
        $friendStatus = ($viewer !== null && ! $isSelf && ! BlockLookup::hasAnyBlockBetween($viewer, $member)) ? match (true) {
            $viewer->isFriendsWith($member) => 'friend',
            $member->hasPendingRequestFrom($viewer) => 'sent',
            $viewer->hasPendingRequestFrom($member) => 'received',
            default => 'none',
        } : null;

        return $this->respondWith($request, 'member', [
            SurfaceResolver::CLASSIC => fn () => view('member.show', [
                'owner' => $member,
                'fields' => $fields,
                'age' => $age,
                'isSelf' => $isSelf,
                'friendStatus' => $friendStatus,
                'lang' => $lang,
                'zones' => $gadgets->zones('profile', subject: $member, viewer: $viewer),
                'layout' => $gadgets->layoutLetter('profile'),
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('member/show', [
                'profile' => ProfileSerializer::page($member, $fields, $isSelf, $lang, $age, $friendStatus),
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
                'form' => ProfileFormSerializer::form($viewer->name, $fields, $lang, $this->ageBlock($viewer)),
            ]),
        ]);
    }

    public function update(UpdateProfileRequest $request, SaveMemberProfile $action): RedirectResponse
    {
        $viewer = $this->viewer();
        $action($viewer, $request->toData());

        // The age-visibility gate is edited next to the birthday it derives from (Modern; Classic
        // keeps its config category). Deliberately persisted whenever submitted, even unchanged —
        // the form showed a concrete value and saving affirms it (the default is a hardcoded
        // Private, so there is no operator default to keep following). Consequence, accepted:
        // AgeVisibility::defaultFor() clamps a stored Open to Members while web-public age is off,
        // so saving the profile in that window persists the clamped value (fail-closed direction).
        $age = $request->validated('age_visibility');
        if ($age !== null && self::birthdayFieldExists()) {
            $viewer->setPreference(PreferenceKey::AgeVisibility, Visibility::from((int) $age));
        }

        return redirect()
            ->route(SurfaceResolver::redirectName($request, 'member.profile.edit'))
            ->with('status', __('Profile updated.'));
    }

    /**
     * Age-visibility block for the Modern edit form, or null when the site has no birthday
     * profile item — without a birthday there is no age, so the setting is not offered.
     *
     * @return array{value: int, options: list<array{value: int, label: string}>}|null
     */
    private function ageBlock(Member $viewer): ?array
    {
        if (! self::birthdayFieldExists()) {
            return null;
        }

        return [
            'value' => AgeVisibility::defaultFor($viewer)->value,
            'options' => array_map(
                fn (Visibility $v): array => ['value' => $v->value, 'label' => __($v->label())],
                AgeVisibility::options(),
            ),
        ];
    }

    /** Site-level gate: the preset birthday profile item exists (independent of its display flags). */
    public static function birthdayFieldExists(): bool
    {
        return Profile::query()->where('name', 'op_preset_birthday')->exists();
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
