<?php

namespace App\Features\Member;

use App\Auth\SessionRevocation;
use App\Features\Member\Serializers\MemberMfaSerializer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\ConfirmMfaRequest;
use App\Http\Requests\Member\DisableMfaRequest;
use App\Http\Requests\Member\MfaManagementRequest;
use App\Models\Member;
use App\Notifications\Member\MfaDisabledNotification;
use App\Notifications\Member\MfaEnabledNotification;
use App\Support\SecurityLog;
use App\Support\SurfaceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;

/**
 * Member two-factor management: Fortify's actions behind this app's management contract —
 * one inline current_password re-auth per flow (enable opens MfaSetupReauth's window for
 * confirm; disabling a live factor and regenerating codes re-auth every time; cancelling an
 * inert pending set-up never does) and other-session revocation when the live factor changes
 * (docs/internals/security.md). Fortify's own /user/two-factor-* endpoints are not registered
 * precisely because they lack both.
 *
 * State machine: disabled → (enable: pending secret + codes) → pending → (confirm: TOTP proof)
 * → enabled. A pending secret never gates login, so cancelling or abandoning set-up is safe.
 */
class MemberMfaController extends Controller
{
    public function edit(Request $request): InertiaResponse
    {
        return Inertia::render('member/config/mfa', MemberMfaSerializer::state($this->viewer(), $request->session()));
    }

    public function enable(MfaManagementRequest $request, EnableTwoFactorAuthentication $enable): RedirectResponse
    {
        $viewer = $this->viewer();

        // Strictly a disabled-state action: with any secret present (pending or confirmed) a
        // parallel enable could rotate the stored secret under a concurrent confirm, which then
        // stamps two_factor_confirmed_at against a secret the member never scanned — a lockout.
        // Restarting a pending set-up is cancel (disable) first, then enable — never a rotation
        // in place.
        abort_if(! blank($viewer->two_factor_secret), 403);

        $enable($viewer);

        // The password was just verified (MfaManagementRequest); the stamp lets confirm finish
        // the same sitting without asking for it a second time.
        MfaSetupReauth::stamp($request->session());

        return $this->mfaRedirect($request);
    }

    public function confirm(ConfirmMfaRequest $request, ConfirmTwoFactorAuthentication $confirm): RedirectResponse
    {
        $viewer = $this->viewer();

        abort_if($viewer->hasEnabledTwoFactorAuthentication(), 403);
        abort_if(blank($viewer->two_factor_secret), 403);

        try {
            // One transaction: the factor turning on and the other-session purge + remember_token
            // rotation must not half-apply (a factor change is a credential change).
            DB::transaction(function () use ($confirm, $viewer, $request): void {
                $confirm($viewer, (string) $request->validated('code'));
                SessionRevocation::revokeMember($viewer, $request->session()->getId());
            });
        } catch (ValidationException) {
            // Fortify raises this in the `confirmTwoFactorAuthentication` named bag; rethrow into
            // the default bag so Classic @error('code') and Inertia errors.code both see it.
            throw ValidationException::withMessages([
                'code' => __('The provided two factor authentication code was invalid.'),
            ]);
        }

        MfaSetupReauth::clear($request->session());
        $request->session()->flash(MemberMfaSerializer::SHOW_RECOVERY_CODES, true);

        // After the factor is live: a security alert to the member's own address (takeover detection).
        $viewer->notify(new MfaEnabledNotification($viewer->locale ?? app()->getLocale()));
        SecurityLog::event('mfa.enabled', ['guard' => 'member', 'member_id' => $viewer->getKey()]);

        return $this->mfaRedirect($request, __('Two-factor authentication is now enabled.'));
    }

    public function disable(DisableMfaRequest $request, DisableTwoFactorAuthentication $disable): RedirectResponse
    {
        $viewer = $this->viewer();

        // Nothing to disable — don't revoke sessions over a no-op (stale tab double-submit).
        if (blank($viewer->two_factor_secret)) {
            return $this->mfaRedirect($request);
        }

        // Read before disabling: only a live (confirmed) factor's removal is a credential change worth
        // revoking sessions over and alerting on. Cancelling a pending set-up is password-free, so it
        // must stay side-effect-free too — otherwise a walked-up session could log out the member's
        // other devices for free.
        $wasEnabled = $viewer->hasEnabledTwoFactorAuthentication();

        DB::transaction(function () use ($disable, $viewer, $request, $wasEnabled): void {
            $disable($viewer);
            if ($wasEnabled) {
                SessionRevocation::revokeMember($viewer, $request->session()->getId());
            }
        });
        MfaSetupReauth::clear($request->session());

        // Cancelling a pending set-up isn't a factor change worth announcing; it lands back on
        // the set-up screen for a restart. Disabling a live factor lands on the settings hub —
        // the Modern detail page's disabled state is the set-up form, which reads as "do it
        // again", not "it is now off"; the hub shows the flash without scrolling and the account
        // row states the new status.
        if (! $wasEnabled) {
            return $this->mfaRedirect($request);
        }

        // The removed factor was live: a security alert to the member's own address.
        $viewer->notify(new MfaDisabledNotification($viewer->locale ?? app()->getLocale()));
        SecurityLog::event('mfa.disabled', ['guard' => 'member', 'member_id' => $viewer->getKey()]);

        $status = __('Two-factor authentication has been disabled.');
        if (SurfaceResolver::resolve($request, 'member') === SurfaceResolver::CLASSIC) {
            return redirect()->route('member.config', ['category' => MemberConfigCategory::Mfa->value])
                ->with('status', $status);
        }

        return redirect()->route('member.config')->with('status', $status);
    }

    public function regenerate(MfaManagementRequest $request, GenerateNewRecoveryCodes $generate): RedirectResponse
    {
        $viewer = $this->viewer();

        abort_unless($viewer->hasEnabledTwoFactorAuthentication(), 403);

        // No session revocation: the TOTP factor is unchanged (admin parity).
        $generate($viewer);
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

    private function viewer(): Member
    {
        $viewer = auth()->user();
        assert($viewer instanceof Member);

        return $viewer;
    }
}
