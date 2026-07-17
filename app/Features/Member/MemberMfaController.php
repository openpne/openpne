<?php

namespace App\Features\Member;

use App\Features\Member\Actions\ConfirmMemberMfa;
use App\Features\Member\Actions\DisableMemberMfa;
use App\Features\Member\Actions\EnableMemberMfa;
use App\Features\Member\Actions\RegenerateMemberRecoveryCodes;
use App\Features\Member\Serializers\MemberMfaSerializer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\ConfirmMfaRequest;
use App\Http\Requests\Member\DisableMfaRequest;
use App\Http\Requests\Member\MfaManagementRequest;
use App\Http\Requests\Member\RegenerateMfaRequest;
use App\Notifications\Member\MfaDisabledNotification;
use App\Notifications\Member\MfaEnabledNotification;
use App\Support\SecurityLog;
use App\Support\SurfaceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Member two-factor management: the surface for this app's management contract. Removing a live factor
 * or regenerating recovery codes re-authenticates every time — the account password AND a second-factor
 * proof (a current TOTP code; disable also accepts an unused recovery code) — matching the admin
 * posture; enabling opens MfaSetupReauth's window so confirm needs only the TOTP code; cancelling an
 * inert pending set-up needs nothing (docs/internals/security.md). A live-factor change revokes the
 * member's other sessions. Fortify's own /user/two-factor-* endpoints are not registered precisely
 * because they lack all of this.
 *
 * State machine: disabled → (enable: pending secret + codes) → pending → (confirm: TOTP proof)
 * → enabled. A pending secret never gates login, so cancelling or abandoning set-up is safe.
 *
 * Each transactional core — the row-locked fresh-state re-derivation, second-factor verification,
 * Fortify mutation, and session revocation — lives in its own feature Action (App\Features\Member\
 * Actions). This surface keeps only the HTTP edge: FormRequest input, the re-auth window, session
 * flash, the audit log + alerts, and redirects. The FormRequest decided required-ness against a
 * pre-controller snapshot, so the Action re-derives the state under the lock and fails closed on a
 * mismatch; `requiresPassword()` is handed to the disable Action as the $stepUpValidated snapshot.
 */
class MemberMfaController extends Controller
{
    public function edit(Request $request): InertiaResponse
    {
        return Inertia::render('member/config/mfa', MemberMfaSerializer::state($this->viewer(), $request->session()));
    }

    public function enable(MfaManagementRequest $request, EnableMemberMfa $enable): RedirectResponse
    {
        $enable($this->viewer());

        // The password was just verified (MfaManagementRequest); the stamp lets confirm finish
        // the same sitting without asking for it a second time.
        MfaSetupReauth::stamp($request->session());

        return $this->mfaRedirect($request);
    }

    public function confirm(ConfirmMfaRequest $request, ConfirmMemberMfa $confirm): RedirectResponse
    {
        $viewer = $this->viewer();

        try {
            $confirm($viewer, (string) $request->validated('code'), $request->session()->getId());
        } catch (ValidationException $e) {
            // Fortify raises the code failure in the `confirmTwoFactorAuthentication` named bag;
            // rethrow it into the default bag so Classic @error('code') and Inertia errors.code
            // both see it. The Action's fail-closed mismatch is already default-bag — let it through
            // unchanged rather than masking it as an invalid code.
            if ($e->errorBag !== 'confirmTwoFactorAuthentication') {
                throw $e;
            }

            throw ValidationException::withMessages([
                'code' => __('The provided two factor authentication code was invalid.'),
            ]);
        }

        MfaSetupReauth::clear($request->session());
        $request->session()->flash(MemberMfaSerializer::SHOW_RECOVERY_CODES, true);

        // The factor is live: log first (enqueueing the alert is fallible and must not suppress the
        // audit record), then the security alert to the member's own address (takeover detection).
        SecurityLog::event('mfa.enabled', ['guard' => 'member', 'member_id' => $viewer->getKey()]);
        $viewer->notify(new MfaEnabledNotification($viewer->locale ?? app()->getLocale()));

        return $this->mfaRedirect($request, __('Two-factor authentication is now enabled.'));
    }

    public function disable(DisableMfaRequest $request, DisableMemberMfa $disable): RedirectResponse
    {
        $viewer = $this->viewer();

        // requiresPassword() is the FormRequest's state snapshot; the Action re-checks it against the
        // locked row and fails closed on a mismatch. $wasEnabled reflects the fresh state, so the
        // log/alert/redirect below branch on what actually happened, not the snapshot.
        $wasEnabled = $disable(
            $viewer,
            $request->requiresPassword(),
            $request->validated('code'),
            $request->validated('recovery_code'),
            $request->session()->getId(),
        );

        MfaSetupReauth::clear($request->session());

        // Cancelling a pending set-up isn't a factor change worth announcing; it lands back on
        // the set-up screen for a restart. Disabling a live factor lands on the settings hub —
        // the Modern detail page's disabled state is the set-up form, which reads as "do it
        // again", not "it is now off"; the hub shows the flash without scrolling and the account
        // row states the new status.
        if (! $wasEnabled) {
            return $this->mfaRedirect($request);
        }

        // The removed factor was live: log first (fallible enqueue must not suppress the audit
        // record), then the security alert to the member's own address.
        SecurityLog::event('mfa.disabled', ['guard' => 'member', 'member_id' => $viewer->getKey()]);
        $viewer->notify(new MfaDisabledNotification($viewer->locale ?? app()->getLocale()));

        $status = __('Two-factor authentication has been disabled.');
        if (SurfaceResolver::resolve($request, 'member') === SurfaceResolver::CLASSIC) {
            return redirect()->route('member.config', ['category' => MemberConfigCategory::Mfa->value])
                ->with('status', $status);
        }

        return redirect()->route('member.config')->with('status', $status);
    }

    public function regenerate(RegenerateMfaRequest $request, RegenerateMemberRecoveryCodes $regenerate): RedirectResponse
    {
        $viewer = $this->viewer();

        $regenerate($viewer, (string) $request->validated('code'));

        SecurityLog::event('mfa.recovery_codes_regenerated', ['guard' => 'member', 'member_id' => $viewer->getKey()]);

        $request->session()->flash(MemberMfaSerializer::SHOW_RECOVERY_CODES, true);

        return $this->mfaRedirect($request);
    }

    /** Back to this surface's two-factor screen: the Classic category page or the Modern detail page. */
    private function mfaRedirect(Request $request, ?string $status = null): RedirectResponse
    {
        $redirect = SurfaceResolver::resolve($request, 'member') === SurfaceResolver::CLASSIC
            ? redirect()->route('member.config', ['category' => MemberConfigCategory::Mfa->value])
            : redirect()->route('member.config.mfa.edit');

        return $status === null ? $redirect : $redirect->with('status', $status);
    }
}
