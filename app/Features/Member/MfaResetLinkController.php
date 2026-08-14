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

/**
 * The token-gated landing for an admin-issued two-factor reset link. Deliberately reachable whether or
 * not the visitor is logged in — the locked-out member opens it as a guest — so it lives outside the
 * authenticated member-config routes, in its own controller so "controller = auth boundary" holds.
 *
 * Boundary invariant (docs/internals/security.md): the admin panel gains no direct account
 * takeover ability. The link is delivered only to the member's registered mailbox and the reset is gated
 * on the member's own account password — both evidence outside the admin's reach — so even a malicious or
 * hijacked admin cannot use this to seize an account. GET only renders (a mail scanner / prefetch must
 * not consume the token or clear a factor); the reset is the POST, password-gated.
 */
class MfaResetLinkController extends Controller
{
    /**
     * Reset landing for the emailed link (token-gated, reachable logged-in or out). GET only renders the
     * password form and never mutates domain state — an already-off factor redirects read-only.
     */
    public function form(Request $request, string $token): View|RedirectResponse|InertiaResponse
    {
        $pending = $this->livePendingReset($token);
        if ($pending === null) {
            return redirect()->route('login')->with('status', __('This two-factor reset link is no longer valid.'));
        }

        if ($wrongMember = $this->rejectIfDifferentMember($pending)) {
            return $wrongMember;
        }

        // GET must not mutate: the factor may already be off (self-service disable, a prior consume), but
        // burning the token here would be a GET-triggered write. Read-only redirect; the POST burns it.
        $member = Member::find($pending->member_id);
        if ($member === null || ! $member->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('login')
                ->with('status', __('Two-factor authentication is already off for this account. Sign in with your password.'));
        }

        // A guest-reachable token landing from mail — the Modern page is the auth shell, not member chrome.
        // Only the token is passed: the member's address/name are never surfaced on the token page.
        if (SurfaceResolver::resolve($request, 'member') === SurfaceResolver::MODERN) {
            return Inertia::render('auth/mfa-reset', ['token' => $token]);
        }

        // Classic shell: the body class tracks the actual auth state so it matches the shell's auth-driven
        // nav/banner (the subject opening their own link while logged in gets secure_page; a guest gets the
        // pre-login insecure_page). A different logged-in member was turned away above.
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

        // Format only — the guest context cannot use the current_password:member rule; ConsumeMfaReset does
        // the Hash::check under the lock, before any mutation, so a wrong guess spends nothing.
        $request->validate(['password' => ['required', 'string']]);

        // ConsumeMfaReset owns every state transition (re-lock, TTL/factor re-check, password proof,
        // disable + burn) and returns an explicit outcome. A wrong password throws ValidationException,
        // landing back on the form with the token intact.
        $result = $consume((int) $pending->member_id, $token, (string) $request->input('password'));

        if ($result->isInvalid()) {
            return redirect()->route('login')->with('status', __('This two-factor reset link is no longer valid.'));
        }

        if ($result->isAlreadyOff()) {
            // The factor was cleared elsewhere after the form loaded; the spent link was burned. Point them
            // at a plain password sign-in (no proof is needed anymore).
            return redirect()->route('login')
                ->with('status', __('Two-factor authentication is already off for this account. Sign in with your password.'));
        }

        $member = $result->member;

        // The factor is gone: log first (a fallible enqueue must not suppress the audit record), then the
        // security alert to the member's own address. No admin_username — the member, not the admin, acted.
        SecurityLog::event('mfa.disabled', ['guard' => 'member', 'member_id' => $member->getKey(), 'via' => 'reset_link']);
        $member->notify(new MfaDisabledNotification($member->locale ?? app()->getLocale()));

        // Removing the factor is a credential change, so all sessions were revoked in ConsumeMfaReset. If
        // the consumer is signed in as this member (they opened the link in their own session), end that
        // session here too, then everyone signs in afresh (email-change idiom).
        if (Auth::guard('member')->id() === $member->getKey()) {
            Auth::guard('member')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()->route('login')
            ->with('status', __('Two-factor authentication has been reset. Sign in with your password.'));
    }

    /**
     * A reset link is the locked-out member's action; completing it while logged in as a DIFFERENT member
     * is incoherent (and would surface that member's identity in the shell). Turn them away with a clear
     * message — they can sign out and reopen the link. A guest, or the subject themselves, proceeds.
     */
    private function rejectIfDifferentMember(MfaResetRequest $pending): ?RedirectResponse
    {
        $current = Auth::guard('member')->id();
        if ($current !== null && (int) $current !== (int) $pending->member_id) {
            return redirect()->route('home')
                ->with('status', __('This confirmation link is for a different account. Please sign out and open it again.'));
        }

        return null;
    }

    /** The live pending reset for a raw token, or null when unknown or past its TTL. */
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
