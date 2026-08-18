<?php

namespace App\Features\Member;

use App\Auth\SessionRevocation;
use App\Features\AiAccount\AiAccountSettings;
use App\Features\AiAccount\AiAccountTokens;
use App\Features\AiAccount\Serializers\AiAccountSerializer;
use App\Features\Diary\DiaryVisibility;
use App\Features\Member\Actions\RequestEmailChange;
use App\Features\Member\Actions\WithdrawMember;
use App\Features\Member\Serializers\MemberConfigSerializer;
use App\Features\Member\Serializers\MemberMfaSerializer;
use App\Features\Notifications\Serializers\NotificationSettingsSerializer;
use App\Features\Profile\AgeVisibility;
use App\Features\Profile\Queries\BirthdayFieldExists;
use App\Http\Controllers\Concerns\RespondsWithSurface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\RequestEmailChangeRequest;
use App\Http\Requests\Member\UpdateAgeVisibilityRequest;
use App\Http\Requests\Member\UpdateDiaryDefaultRequest;
use App\Http\Requests\Member\UpdateLookRequest;
use App\Http\Requests\Member\UpdatePasswordRequest;
use App\Http\Requests\Member\UpdatePreferredSurfaceRequest;
use App\Http\Requests\Member\WithdrawalRequest;
use App\Models\EmailChangeRequest;
use App\Notifications\Member\PasswordChangedNotification;
use App\Support\Feature;
use App\Support\LookResolver;
use App\Support\PreferenceKey;
use App\Support\SecurityLog;
use App\Support\Surface;
use App\Support\SurfaceResolver;
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
 * The member's own settings page, Classic + Modern. Each section is its
 * own submit so saving one never rewrites another — in particular, the diary section shows the
 * read-time clamped default (DiaryVisibility::defaultFor), which must not be written back on an
 * unrelated change (it would collapse a stored Open once web-public is off).
 */
class MemberConfigController extends Controller
{
    use RespondsWithSurface;

    public function __construct(private readonly AiAccountSettings $aiSettings) {}

    public function show(Request $request, BirthdayFieldExists $birthdayExists): View|InertiaResponse|RedirectResponse
    {
        // OpenPNE 3 access-block lived at /member/config?category=accessBlock; preserve that URL by
        // resolving just that category to the canonical Block list.
        if ($request->query('category') === 'accessBlock') {
            return redirect()->route('block.list');
        }

        // OpenPNE 3 split the mail-address change into pcAddress/mobileAddress; OpenPNE 4 has a single
        // email category (no mobile email). Redirect the known legacy key so a bookmarked URL lands.
        if ($request->query('category') === 'pcAddress') {
            return redirect()->route('member.config', ['category' => MemberConfigCategory::Email->value]);
        }

        $viewer = $this->viewer();
        $currentSurface = Surface::from(SurfaceResolver::canonicalSurface($request, 'member'));

        return $this->respondWith($request, 'member', [
            // Classic paginates by ?category= (OpenPNE 3 member/config). An absent / non-string /
            // unrecognized value resolves to null = the "select an item" landing (no 404 — OpenPNE 4
            // keeps unknown categories renderable; only accessBlock redirects, handled above). Resolved
            // inside the Classic closure so the Modern single page never sees ?category=.
            SurfaceResolver::CLASSIC => function () use ($viewer, $currentSurface, $request, $birthdayExists) {
                $raw = $request->query('category');
                $category = is_string($raw) ? MemberConfigCategory::tryFrom($raw) : null;

                // Without a birthday profile item there is no age, so the age category is dead
                // weight: hide it from the nav and fold its URL into the landing (a deliberate
                // divergence from OpenPNE 3, which always shows it).
                $ageAvailable = $birthdayExists();
                if ($category === MemberConfigCategory::PublicFlag && ! $ageAvailable) {
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
                ]);
            }, // Modern serves no age section — its setter lives on the profile-edit form.
            SurfaceResolver::MODERN => fn () => Inertia::render('member/config', [
                'form' => MemberConfigSerializer::form($viewer, $currentSurface, $this->aiSettings),
            ]),
        ]);
    }

    /**
     * Modern-only detail pages for the consequential account changes. The settings page shows a
     * compact row per item; the actual forms live one level deeper so a validation error returns
     * to a short, focused page instead of the bottom of the settings list.
     */
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

    // Classic-only since the Modern setter moved next to the birthday on the profile-edit form
    // (the Modern sibling route was removed with it).
    public function updateAge(UpdateAgeVisibilityRequest $request, BirthdayFieldExists $birthdayExists): RedirectResponse
    {
        // Same gate as every surface that offers the setting: without a birthday item there is no
        // age, so a crafted POST persists nothing and lands where the hidden category's URL does.
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

        // Keep this device, drop the others. The new hash makes every session's stored password hash
        // stale; auth.session (AuthenticateSession) re-stores THIS session's hash after the response so
        // the current device survives, and bounces the others on their next protected request.
        // logoutOtherDevices re-hashes the just-set password and fires the other-device-logout event —
        // it verifies against the current hash, so it runs after the save. Neither this nor auth.session
        // deletes DB session rows; that is ResetMemberPassword's compromise-path behavior, not an
        // in-session change's.
        Auth::guard('member')->logoutOtherDevices($newPassword);

        // The other-device sweep, one step out: a token minted for an AI account this member owns is
        // reached with the old password's authority, so it drops with the other devices rather than
        // outliving them. Same treatment as the reset path's.
        AiAccountTokens::revokeOwnedBy($viewer);

        // Compensating control for the notify-only email change: a stolen-password attacker could have
        // requested an email change, so a password change must void any pending one — otherwise the
        // attacker still holds a live confirmation token for the new address.
        EmailChangeRequest::where('member_id', $viewer->getKey())->delete();

        // Log before the alert: enqueueing the notification is fallible and must not be able to
        // suppress the audit record of a change that is already durable.
        SecurityLog::event('password.changed', ['guard' => 'member', 'member_id' => $viewer->getKey()]);

        // Security alert to the member's own address (takeover detection).
        $viewer->notify(new PasswordChangedNotification($viewer->locale ?? app()->getLocale()));

        return $this->savedRedirect($request, MemberConfigCategory::Password);
    }

    public function updateEmail(RequestEmailChangeRequest $request, RequestEmailChange $requestChange): RedirectResponse
    {
        $viewer = $this->viewer();
        $newEmail = (string) $request->validated('new_email');
        // email.change_requested is logged inside the action, between the durable token write and
        // the fallible notification sends.
        $requestChange($viewer, $newEmail);

        // members.email is unchanged until confirmation; tell the member to open the link just mailed.
        $params = SurfaceResolver::resolve($request, 'member') === SurfaceResolver::CLASSIC
            ? ['category' => MemberConfigCategory::Email->value]
            : [];

        return redirect()->route('member.config', $params)
            ->with('status', __('We sent a confirmation link to your new email address. Open it to finish the change.'));
    }

    public function withdraw(WithdrawalRequest $request, WithdrawMember $withdraw): Response
    {
        $member = $this->viewer();

        // Log out BEFORE deleting. A full logout cycles remember_token through the user provider (a
        // save()); running it after the row is gone would re-insert the just-withdrawn member. Logging
        // out first also nulls the guard user, so auth.session's post-response hook does nothing.
        Auth::guard('member')->logout();

        $withdraw($member);

        // Drop the member's other devices too: sessions.user_id carries no FK to members, so deleting
        // the member leaves its session rows behind (purge-only — the member row is gone, and logout
        // above already cycled remember_token). Then reset the current session.
        SessionRevocation::purgeMemberSessions((int) $member->getKey());
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->flash('status', __('Your account has been deleted.'));

        $target = route('login');

        return $request->hasHeader('X-Inertia') ? Inertia::location($target) : redirect($target);
    }

    public function updateSurface(UpdatePreferredSurfaceRequest $request): Response
    {
        // Hard gate, not just a hidden picker: under modern_only a crafted POST could otherwise write a
        // latent preferred_surface=classic row that would fire if the site later switches to a coexistence
        // mode. Classic is unavailable, so there is no surface to pin.
        abort_unless(SurfaceResolver::classicAvailable(), 403);

        $chosen = Surface::from($request->validated('preferred_surface'));
        $viewer = $this->viewer();

        // Pin only an actual change. Saving the surface the member is already on (their stored choice,
        // or the gate/default they currently follow when unset) is a no-op, so it neither pins an
        // unset member nor strips the operator's ability to move them later — the binary UI's stand-in
        // for a "disabled until changed" button, enforced the same way on both surfaces. canonicalSurface
        // honours modern_status/modern_only, so a member already forced onto a surface is never pinned.
        $changed = $chosen->value !== SurfaceResolver::canonicalSurface($request, 'member');
        if ($changed) {
            $viewer->setPreferredSurface($chosen);
            $request->session()->flash('status', __('Settings updated.'));
        }

        // Land on the config page through a full page load (Inertia::location below): the
        // just-written preference resolves the chosen surface there, and the full load re-renders
        // the whole shell — an XHR redirect would keep the Modern SPA alive on a Classic choice.
        $target = $chosen === Surface::Modern
            ? route('member.config')
            : route('member.config', ['category' => MemberConfigCategory::General->value]);

        return $request->hasHeader('X-Inertia') ? Inertia::location($target) : redirect($target);
    }

    /**
     * The layout picker: a detail page that describes what each look does differently, since a look
     * is a way around the site rather than something a radio label can name.
     */
    public function editLook(): InertiaResponse|RedirectResponse
    {
        // The same gate as the hub row, which is absent for the same reason: with one selectable
        // look there is nothing to choose between. Lands on the settings page rather than 404ing,
        // like every other member-config section whose gate closes under a member.
        if (count(LookResolver::selectable()) < 2) {
            return redirect()->route('member.config');
        }

        return Inertia::render('member/config/look', [
            'lookChoice' => MemberConfigSerializer::lookForm($this->viewer()),
        ]);
    }

    /** Keep the chosen look. `default` clears the choice, back to following the site's. */
    public function updateLook(UpdateLookRequest $request): Response
    {
        $look = $request->look();

        if ($look === null) {
            $this->viewer()->resetPreferredLook();
        } else {
            $this->viewer()->setPreferredLook($look);
        }

        $request->session()->flash('status', __('Settings updated.'));

        // Back to the picker, where the whole shell re-rendering in the chosen look is the
        // confirmation the page itself cannot give.
        return $this->fullLoad($request, route('member.config.look.edit'));
    }

    /**
     * Land on $target through a full page load. Every look POST changes what the whole shell renders,
     * and an XHR redirect would leave the running SPA drawing the previous one.
     */
    private function fullLoad(Request $request, string $target): Response
    {
        return $request->hasHeader('X-Inertia') ? Inertia::location($target) : redirect($target);
    }

    /**
     * Redirect back to the just-saved section: the Classic category page (`?category=`), or the bare
     * Modern config page on Modern (single page, no category). Gating the param to the Classic route
     * keeps the Modern redirect category-free. An instant-apply preference (diary/age) passes
     * `flashOnModern: false` — Modern announces those saves inline next to the control, so the page
     * flash would say the same thing twice; Classic always keeps the flash (its category pages have
     * no inline indicator).
     */
    private function savedRedirect(Request $request, MemberConfigCategory $category, bool $flashOnModern = true): RedirectResponse
    {
        $isClassic = SurfaceResolver::resolve($request, 'member') === SurfaceResolver::CLASSIC;
        $redirect = redirect()->route('member.config', $isClassic ? ['category' => $category->value] : []);

        return $isClassic || $flashOnModern ? $redirect->with('status', __('Settings updated.')) : $redirect;
    }
}
