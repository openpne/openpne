<?php

namespace App\Features\Profile;

use App\Features\Block\BlockLookup;
use App\Features\Diary\Queries\RecentMemberDiaries;
use App\Features\Friend\Queries\ListFriends;
use App\Features\Group\Queries\ListMemberGroups;
use App\Features\Notifications\ConsumeNotificationRows;
use App\Features\Notifications\NotificationTarget;
use App\Features\Profile\Actions\SaveMemberProfile;
use App\Features\Profile\Queries\BirthdayFieldExists;
use App\Features\Profile\Queries\EditProfileFields;
use App\Features\Profile\Queries\ProfileStats;
use App\Features\Profile\Queries\ShowProfile;
use App\Features\Profile\Queries\VisibleAge;
use App\Features\Profile\Serializers\ProfileFormSerializer;
use App\Features\Profile\Serializers\ProfileSerializer;
use App\Features\Profile\Serializers\UnifiedMemberSerializer;
use App\Http\Controllers\Concerns\RespondsWithSurface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Models\Member;
use App\Services\GadgetService;
use App\Support\Feature;
use App\Support\GuestLoginRedirect;
use App\Support\LookResolver;
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

    public function show(Request $request, Member $member, ShowProfile $query, GadgetService $gadgets, VisibleAge $visibleAge, ConsumeNotificationRows $feedRows): View|InertiaResponse|RedirectResponse
    {
        /** @var Member|null $viewer */
        $viewer = $request->user();

        if ($viewer === null && ! ProfileAccess::isWebPublic($member)) {
            return GuestLoginRedirect::response();
        }

        $this->memberSubject($member); // 404 when the owner has blocked the viewer

        $lang = $this->translationLang();
        $fields = $query($viewer, $member, $lang);
        abort_if($fields === null, 404); // defense in depth: ShowProfile also nulls on block

        $isSelf = $viewer?->is($member) ?? false;
        $age = $visibleAge($viewer, $member);

        // memberSubject only 404s the owner→viewer block, and the friend form refuses either
        // direction, so a viewer who blocks the owner gets no entry either.
        $friendStatus = (Feature::Friend->enabled() && $viewer !== null && ! $isSelf && ! BlockLookup::hasAnyBlockBetween($viewer, $member)) ? match (true) {
            $viewer->isFriendsWith($member) => 'friend',
            $member->hasPendingRequestFrom($viewer) => 'sent',
            $viewer->hasPendingRequestFrom($member) => 'received',
            default => 'none',
        } : null;

        if ($viewer !== null) {
            $feedRows->markTargetsRead((int) $viewer->getKey(), NotificationTarget::member((int) $member->getKey()));
        }

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
            SurfaceResolver::MODERN => function () use ($request, $member, $fields, $isSelf, $lang, $age, $friendStatus, $viewer) {
                // Branching first keeps the digest below from being gathered for a page that never
                // shows it.
                if (LookResolver::resolve($request)->usesUnifiedPages()) {
                    return Inertia::render('unified/member', UnifiedMemberSerializer::page(
                        $viewer, $member, $fields, $isSelf, $lang, $age, $friendStatus,
                    ));
                }

                // Auth-only: the previews and stats link to routes behind the auth group.
                $digest = $viewer === null ? null : ProfileSerializer::digest(
                    (new ProfileStats)($viewer, $member),
                    Feature::Diary->enabled() ? (new RecentMemberDiaries)($viewer, $member, 3)->load('images.file') : collect(),
                    // 10 tiles fill the 5-column grid's two rows; NineTable trims to 9 (3×3) on mobile.
                    Feature::Friend->enabled() ? (new ListFriends)->take($viewer, $member, 10) : collect(),
                    Feature::Group->enabled() ? (new ListMemberGroups)->take($member, 10) : collect(),
                );

                return Inertia::render('member/show', [
                    'profile' => ProfileSerializer::page($member, $fields, $isSelf, $lang, $age, $friendStatus),
                    'digest' => $digest,
                ]);
            },
        ]);
    }

    public function edit(Request $request, EditProfileFields $query, BirthdayFieldExists $birthdayExists): View|InertiaResponse
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
                'form' => ProfileFormSerializer::form($viewer->name, $fields, $lang, $this->ageBlock($viewer, $birthdayExists)),
            ]),
        ]);
    }

    public function update(UpdateProfileRequest $request, SaveMemberProfile $action, BirthdayFieldExists $birthdayExists): RedirectResponse
    {
        $viewer = $this->viewer();
        $action($viewer, $request->toData());

        // Persisted whenever submitted, even unchanged, so a save while web-public age is off stores
        // defaultFor()'s clamped Members over a stored Open.
        $age = $request->validated('age_visibility');
        if ($age !== null && $birthdayExists()) {
            $viewer->setPreference(PreferenceKey::AgeVisibility, Visibility::from((int) $age));
        }

        return redirect()
            ->route('member.profile.edit')
            ->with('status', __('Profile updated.'));
    }

    /** @return array{value: int, options: list<array{value: int, label: string}>}|null */
    private function ageBlock(Member $viewer, BirthdayFieldExists $birthdayExists): ?array
    {
        if (! $birthdayExists()) {
            return null;
        }

        return [
            'value' => AgeVisibility::defaultFor($viewer)->value,
            'options' => array_map(
                fn (Visibility $v): array => ['value' => $v->value, 'label' => __($v->label())],
                AgeVisibility::optionsFor($viewer),
            ),
        ];
    }

    /** OpenPNE 3 (Doctrine I18n) lang codes: `ja_JP`, not `ja`. */
    private function translationLang(): string
    {
        return app()->getLocale() === 'ja' ? 'ja_JP' : 'en';
    }
}
