<?php

namespace App\Features\Member;

use App\Auth\SessionRevocation;
use App\Features\AiAccount\AiAccountSettings;
use App\Features\AiAccount\AiAccountTokens;
use App\Features\AiAccount\Serializers\AiAccountSerializer;
use App\Features\Diary\DiaryVisibility;
use App\Features\GroupTalk\Queries\MutedTalkRooms;
use App\Features\Member\Actions\RequestEmailChange;
use App\Features\Member\Actions\WithdrawMember;
use App\Features\Member\Serializers\MemberConfigSerializer;
use App\Features\Member\Serializers\MemberMfaSerializer;
use App\Features\Notifications\Serializers\NotificationSettingsSerializer;
use App\Features\Profile\AgeVisibility;
use App\Features\Profile\ProfilePageVisibility;
use App\Features\Profile\Queries\BirthdayFieldExists;
use App\Http\Controllers\Concerns\RespondsWithSurface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\RequestEmailChangeRequest;
use App\Http\Requests\Member\UpdateAgeVisibilityRequest;
use App\Http\Requests\Member\UpdateDiaryDefaultRequest;
use App\Http\Requests\Member\UpdateLookRequest;
use App\Http\Requests\Member\UpdatePasswordRequest;
use App\Http\Requests\Member\UpdatePreferredSurfaceRequest;
use App\Http\Requests\Member\UpdateProfileVisibilityRequest;
use App\Http\Requests\Member\WithdrawalRequest;
use App\Models\EmailChangeRequest;
use App\Notifications\Member\PasswordChangedNotification;
use App\Support\Feature;
use App\Support\LookResolver;
use App\Support\PreferenceKey;
use App\Support\SecurityLog;
use App\Support\Surface;
use App\Support\SurfaceResolver;
use App\Support\Visibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Each section is its own submit, so saving one never writes back another's read-time clamped
 * default — a stored Open would collapse once web-public is off.
 */
class MemberConfigController extends Controller
{
    use RespondsWithSurface;

    public function __construct(private readonly AiAccountSettings $aiSettings) {}

    public function show(Request $request, BirthdayFieldExists $birthdayExists, MutedTalkRooms $mutedTalkRooms): View|InertiaResponse|RedirectResponse
    {
        // OpenPNE 3's access-block URL (docs/internals/classic-compatibility.md, "URLs are canonical,
        // not Classic-only").
        if ($request->query('category') === 'accessBlock') {
            return redirect()->route('block.list');
        }

        // OpenPNE 3 split this into pcAddress/mobileAddress; OpenPNE 4 has one email category.
        if ($request->query('category') === 'pcAddress') {
            return redirect()->route('member.config', ['category' => MemberConfigCategory::Email->value]);
        }

        $viewer = $this->viewer();
        $currentSurface = Surface::from(SurfaceResolver::canonicalSurface($request, 'member'));

        return $this->respondWith($request, 'member', [
            // An absent or unrecognized `?category=` is the landing, never a 404.
            SurfaceResolver::CLASSIC => function () use ($viewer, $currentSurface, $request, $birthdayExists, $mutedTalkRooms) {
                $raw = $request->query('category');
                $category = is_string($raw) ? MemberConfigCategory::tryFrom($raw) : null;

                // With neither an age to gate nor a profile-page choice the privacy category is dead
                // weight, hidden from the nav and folded into the landing where OpenPNE 3 always showed it.
                $ageAvailable = $birthdayExists();
                $profileChoice = ProfilePageVisibility::memberMayChoose();
                $publicFlagAvailable = ProfilePageVisibility::privacyCategoryAvailable($ageAvailable);
                if ($category === MemberConfigCategory::PublicFlag && ! $publicFlagAvailable) {
                    $category = null;
                }

                // Same fold for a switched-off diary: the section is gone from the nav, and its URL
                // lands on the landing rather than a form whose POST target 404s.
                if ($category === MemberConfigCategory::Diary && ! Feature::Diary->enabled()) {
                    $category = null;
                }

                // AI accounts hide only from a member who has neither the offer nor any account:
                // the setting is creation-only, so an owner keeps their way in after it is switched off.
                $aiAvailable = $this->aiSettings->enabled() || $viewer->aiAccounts()->exists();
                if ($category === MemberConfigCategory::Ai && ! $aiAvailable) {
                    $category = null;
                }

                return view('member.config', [
                    'category' => $category,
                    'ageAvailable' => $ageAvailable,
                    'profileChoice' => $profileChoice,
                    'publicFlagAvailable' => $publicFlagAvailable,
                    'profileDefault' => ProfilePageVisibility::defaultFor($viewer),
                    'profileOptions' => ProfilePageVisibility::options(),
                    'aiAvailable' => $aiAvailable,
                    'ai' => $category === MemberConfigCategory::Ai
                        ? AiAccountSerializer::list($viewer, $this->aiSettings)
                        : null,
                    'diaryDefault' => DiaryVisibility::defaultFor($viewer),
                    'diaryOptions' => DiaryVisibility::options(),
                    'ageDefault' => AgeVisibility::defaultFor($viewer),
                    'ageOptions' => AgeVisibility::optionsFor($viewer),
                    'locale' => app()->getLocale(),
                    'currentSurface' => $currentSurface,
                    'email' => $viewer->email,
                    // Computed only for its own category: the pending branch decrypts the secret,
                    // which no other page needs in scope.
                    'mfa' => $category === MemberConfigCategory::Mfa
                        ? MemberMfaSerializer::state($viewer, $request->session())
                        : null,
                    'notificationGroups' => $category === MemberConfigCategory::Notification
                        ? NotificationSettingsSerializer::form($viewer)['groups']
                        : null,
                    'mutedTalkRooms' => $category === MemberConfigCategory::Notification
                        ? $mutedTalkRooms($viewer)
                        : null,
                ]);
            }, // Modern serves no age section — its setter lives on the profile-edit form.
            SurfaceResolver::MODERN => fn () => Inertia::render('member/config', [
                'form' => MemberConfigSerializer::form($viewer, $currentSurface, $this->aiSettings),
            ]),
        ]);
    }

    public function editEmail(): InertiaResponse
    {
        return Inertia::render('member/config/email', ['email' => $this->viewer()->email]);
    }

    public function editPassword(): InertiaResponse
    {
        return Inertia::render('member/config/password');
    }

    public function editWithdrawal(): InertiaResponse
    {
        return Inertia::render('member/config/withdrawal');
    }

    public function updateDiary(UpdateDiaryDefaultRequest $request): RedirectResponse
    {
        $value = PreferenceKey::DiaryDefaultVisibility->coerce($request->validated('diary_default_visibility'));
        $this->viewer()->setPreference(PreferenceKey::DiaryDefaultVisibility, $value);

        return $this->savedRedirect($request, MemberConfigCategory::Diary, flashOnModern: false);
    }

    public function updateProfileVisibility(UpdateProfileVisibilityRequest $request): RedirectResponse
    {
        // Under a policy that decides for everyone the section is hidden, and a crafted POST
        // persists nothing, landing where the hidden category's URL does.
        if (! ProfilePageVisibility::memberMayChoose()) {
            return redirect()->route('member.config');
        }

        $viewer = $this->viewer();
        $viewer->profile_visibility = Visibility::from((int) $request->validated('profile_visibility'));
        $viewer->save();

        return $this->savedRedirect($request, MemberConfigCategory::PublicFlag, flashOnModern: false);
    }

    // Classic-only: the Modern setter lives on the profile-edit form.
    public function updateAge(UpdateAgeVisibilityRequest $request, BirthdayFieldExists $birthdayExists): RedirectResponse
    {
        // Without a birthday item a crafted POST persists nothing, landing where the hidden
        // category's URL does.
        if (! $birthdayExists()) {
            return redirect()->route('member.config');
        }

        $value = PreferenceKey::AgeVisibility->coerce($request->validated('age_visibility'));
        $this->viewer()->setPreference(PreferenceKey::AgeVisibility, $value);

        return $this->savedRedirect($request, MemberConfigCategory::PublicFlag);
    }

    public function updatePassword(UpdatePasswordRequest $request): RedirectResponse
    {
        $viewer = $this->viewer();
        $newPassword = $request->validated('password');

        // Set the new password and rotate remember_token so old "remember me" cookies die.
        $viewer->forceFill([
            'password' => Hash::make($newPassword),
            'remember_token' => Str::random(60),
        ])->save();

        // After the save, since it verifies against the current hash: the other devices are bounced on
        // their next request rather than having their session rows deleted.
        Auth::guard('member')->logoutOtherDevices($newPassword);

        // A token minted for an owned AI account carries the old password's authority, so it drops
        // with the other devices.
        AiAccountTokens::revokeOwnedBy($viewer);

        // A stolen-password attacker could have requested an email change, so a password change voids
        // any pending one.
        EmailChangeRequest::where('member_id', $viewer->getKey())->delete();

        // Logged before the fallible enqueue, which must not suppress the audit record.
        SecurityLog::event('password.changed', ['guard' => 'member', 'member_id' => $viewer->getKey()]);

        $viewer->notify(new PasswordChangedNotification($viewer->locale ?? app()->getLocale()));

        return $this->savedRedirect($request, MemberConfigCategory::Password);
    }

    public function updateEmail(RequestEmailChangeRequest $request, RequestEmailChange $requestChange): RedirectResponse
    {
        $viewer = $this->viewer();
        $newEmail = (string) $request->validated('new_email');
        $requestChange($viewer, $newEmail);

        $params = SurfaceResolver::resolve($request, 'member') === SurfaceResolver::CLASSIC
            ? ['category' => MemberConfigCategory::Email->value]
            : [];

        return redirect()->route('member.config', $params)
            ->with('status', __('We sent a confirmation link to your new email address. Open it to finish the change.'));
    }

    public function withdraw(WithdrawalRequest $request, WithdrawMember $withdraw): Response
    {
        $member = $this->viewer();

        // Before the delete: a logout cycles `remember_token` through the provider, which afterwards
        // would re-insert the withdrawn row.
        Auth::guard('member')->logout();

        $withdraw($member);

        // `sessions.user_id` carries no FK to members, so the delete leaves its session rows behind.
        SessionRevocation::purgeMemberSessions((int) $member->getKey());
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->flash('status', __('Your account has been deleted.'));

        $target = route('login');

        return $request->hasHeader('X-Inertia') ? Inertia::location($target) : redirect($target);
    }

    public function updateSurface(UpdatePreferredSurfaceRequest $request): Response
    {
        // Hard gate, not just a hidden picker: a crafted POST under `modern_only` would write a latent
        // `preferred_surface=classic` that fires if the site later allows Classic.
        abort_unless(SurfaceResolver::classicAvailable(), 403);

        $chosen = Surface::from($request->validated('preferred_surface'));
        $viewer = $this->viewer();

        // Only an actual change pins: saving the surface the member already follows would strip the
        // operator's ability to move them later.
        $changed = $chosen->value !== SurfaceResolver::canonicalSurface($request, 'member');
        if ($changed) {
            $viewer->setPreferredSurface($chosen);
            $request->session()->flash('status', __('Settings updated.'));
        }

        // A full load, since an XHR redirect would keep the Modern SPA alive on a Classic choice.
        $target = $chosen === Surface::Modern
            ? route('member.config')
            : route('member.config', ['category' => MemberConfigCategory::General->value]);

        return $request->hasHeader('X-Inertia') ? Inertia::location($target) : redirect($target);
    }

    public function editLook(): InertiaResponse|RedirectResponse
    {
        // With one selectable look there is nothing to choose, and a closed gate lands on the settings
        // page rather than 404ing.
        if (count(LookResolver::selectable()) < 2) {
            return redirect()->route('member.config');
        }

        return Inertia::render('member/config/look', [
            'lookChoice' => MemberConfigSerializer::lookForm($this->viewer()),
        ]);
    }

    /** `default` clears the choice, back to following the site's look. */
    public function updateLook(UpdateLookRequest $request): Response
    {
        $look = $request->look();

        if ($look === null) {
            $this->viewer()->resetPreferredLook();
        } else {
            $this->viewer()->setPreferredLook($look);
        }

        $request->session()->flash('status', __('Settings updated.'));

        return $this->fullLoad($request, route('member.config.look.edit'));
    }

    /**
     * Every look POST changes what the whole shell renders, so an XHR redirect would leave the running
     * SPA drawing the previous one.
     */
    private function fullLoad(Request $request, string $target): Response
    {
        return $request->hasHeader('X-Inertia') ? Inertia::location($target) : redirect($target);
    }

    /**
     * `flashOnModern: false` suits an instant-apply preference, which Modern announces inline; Classic
     * always keeps the flash.
     */
    private function savedRedirect(Request $request, MemberConfigCategory $category, bool $flashOnModern = true): RedirectResponse
    {
        $isClassic = SurfaceResolver::resolve($request, 'member') === SurfaceResolver::CLASSIC;
        $redirect = redirect()->route('member.config', $isClassic ? ['category' => $category->value] : []);

        return $isClassic || $flashOnModern ? $redirect->with('status', __('Settings updated.')) : $redirect;
    }
}
