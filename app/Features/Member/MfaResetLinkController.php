<?php

namespace App\Features\Member;

use App\Features\Member\Actions\ConsumeMfaReset;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MfaResetRequest;
use App\Notifications\Member\MfaDisabledNotification;
use App\Support\SecurityLog;
use App\Support\SurfaceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/** Guest-reachable and token-gated, since the locked-out member opens it as a guest. */
class MfaResetLinkController extends Controller
{
    /** GET only renders and never mutates: an already-off factor redirects read-only. */
    public function form(Request $request, string $token): View|RedirectResponse|InertiaResponse
    {
        $pending = $this->livePendingReset($token);
        if ($pending === null) {
            return redirect()->route('login')->with('status', __('This two-factor reset link is no longer valid.'));
        }

        if ($wrongMember = $this->rejectIfDifferentMember($pending)) {
            return $wrongMember;
        }

        $member = Member::find($pending->member_id);
        if ($member === null || ! $member->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('login')
                ->with('status', __('Two-factor authentication is already off for this account. Sign in with your password.'));
        }

        // The Modern page is the auth shell, and only the token is passed: no address, no name.
        if (SurfaceResolver::resolve($request, 'member') === SurfaceResolver::MODERN) {
            return Inertia::render('auth/mfa-reset', ['token' => $token]);
        }

        // The body class tracks the actual auth state, so the OpenPNE 3 skin styles a page the shell's
        // nav matches.
        return view('member.mfa-reset', ['token' => $token])
            ->with('pageId', 'page_member_mfaReset')
            ->with('pageClass', auth()->check() ? 'secure_page' : 'insecure_page');
    }

    public function reset(Request $request, string $token, ConsumeMfaReset $consume): RedirectResponse
    {
        // Token lookup and the different-member reject run BEFORE password validation: an empty password
        // from a DIFFERENT logged-in member must land on the home reject, not a validation redirect that
        // leaks that the link is live.
        $pending = $this->livePendingReset($token);
        if ($pending === null) {
            return redirect()->route('login')->with('status', __('This two-factor reset link is no longer valid.'));
        }

        if ($wrongMember = $this->rejectIfDifferentMember($pending)) {
            return $wrongMember;
        }

        // Format only: the guest context cannot use the `current_password:member` rule.
        $request->validate(['password' => ['required', 'string']]);

        // A wrong password throws, landing back on the form with the token intact.
        $result = $consume((int) $pending->member_id, $token, (string) $request->input('password'));

        if ($result->isInvalid()) {
            return redirect()->route('login')->with('status', __('This two-factor reset link is no longer valid.'));
        }

        if ($result->isAlreadyOff()) {
            // The factor was cleared elsewhere after the form loaded, and the spent link was burned.
            return redirect()->route('login')
                ->with('status', __('Two-factor authentication is already off for this account. Sign in with your password.'));
        }

        $member = $result->member;

        // Logged before the fallible enqueue, with no admin_username: the member acted, not the admin.
        SecurityLog::event('mfa.disabled', ['guard' => 'member', 'member_id' => $member->getKey(), 'via' => 'reset_link']);
        $member->notify(new MfaDisabledNotification($member->locale ?? app()->getLocale()));

        // ConsumeMfaReset revoked every session, but not this request's, so the subject's own ends here.
        if (Auth::guard('member')->id() === $member->getKey()) {
            Auth::guard('member')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()->route('login')
            ->with('status', __('Two-factor authentication has been reset. Sign in with your password.'));
    }

    /** A guest or the subject proceeds; a different logged-in member is turned away. */
    private function rejectIfDifferentMember(MfaResetRequest $pending): ?RedirectResponse
    {
        $current = Auth::guard('member')->id();
        if ($current !== null && (int) $current !== (int) $pending->member_id) {
            return redirect()->route('home')
                ->with('status', __('This confirmation link is for a different account. Please sign out and open it again.'));
        }

        return null;
    }

    private function livePendingReset(string $rawToken): ?MfaResetRequest
    {
        $row = MfaResetRequest::where('token', hash('sha256', $rawToken))->first();
        if ($row === null || $row->created_at === null) {
            return null;
        }

        $ttl = (int) config('openpne.mfa_reset.token_ttl_minutes');

        return $row->created_at->gt(now()->subMinutes($ttl)) ? $row : null;
    }
}
