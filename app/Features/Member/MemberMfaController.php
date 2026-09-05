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

/** See docs/internals/security.md, "Member two-factor authentication". */
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
            // Fortify raises the code failure in its `confirmTwoFactorAuthentication` bag; the
            // Action's own mismatch is already default-bag and passes through unchanged.
            if ($e->errorBag !== 'confirmTwoFactorAuthentication') {
                throw $e;
            }

            throw ValidationException::withMessages([
                'code' => __('The provided two factor authentication code was invalid.'),
            ]);
        }

        MfaSetupReauth::clear($request->session());
        $request->session()->flash(MemberMfaSerializer::SHOW_RECOVERY_CODES, true);

        // Logged before the fallible enqueue, which must not suppress the audit record.
        SecurityLog::event('mfa.enabled', ['guard' => 'member', 'member_id' => $viewer->getKey()]);
        $viewer->notify(new MfaEnabledNotification($viewer->locale ?? app()->getLocale()));

        return $this->mfaRedirect($request, __('Two-factor authentication is now enabled.'));
    }

    public function disable(DisableMfaRequest $request, DisableMemberMfa $disable): RedirectResponse
    {
        $viewer = $this->viewer();

        // `requiresPassword()` is the FormRequest's snapshot, which the Action re-checks under the
        // lock; `$wasEnabled` is the fresh state the branches below read.
        $wasEnabled = $disable(
            $viewer,
            $request->requiresPassword(),
            $request->validated('code'),
            $request->validated('recovery_code'),
            $request->session()->getId(),
        );

        MfaSetupReauth::clear($request->session());

        // A cancelled set-up lands back on the set-up screen; a disabled live factor lands on the hub,
        // since the detail page's disabled state reads as a restart.
        if (! $wasEnabled) {
            return $this->mfaRedirect($request);
        }

        // Logged before the fallible enqueue, which must not suppress the audit record.
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

    private function mfaRedirect(Request $request, ?string $status = null): RedirectResponse
    {
        $redirect = SurfaceResolver::resolve($request, 'member') === SurfaceResolver::CLASSIC
            ? redirect()->route('member.config', ['category' => MemberConfigCategory::Mfa->value])
            : redirect()->route('member.config.mfa.edit');

        return $status === null ? $redirect : $redirect->with('status', $status);
    }
}
