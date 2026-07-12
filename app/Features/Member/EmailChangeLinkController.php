<?php

namespace App\Features\Member;

use App\Auth\SessionRevocation;
use App\Features\Member\Actions\CancelEmailChange;
use App\Features\Member\Actions\ConfirmEmailChange;
use App\Http\Controllers\Controller;
use App\Models\EmailChangeRequest;
use App\Models\Member;
use App\Support\SecurityLog;
use App\Support\SurfaceResolver;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The token-gated email-change mail-link landings (confirm + cancel). These are deliberately
 * reachable whether or not the visitor is logged in — the member may open the link on a different
 * device — so they live outside the authenticated member-config routes. Kept in their own controller
 * so "controller = auth boundary" holds: MemberConfigController is authenticated-only, this one is
 * guest-reachable and token-gated.
 */
class EmailChangeLinkController extends Controller
{
    /**
     * Confirmation landing for the emailed link (token-gated, reachable logged-in or out). GET only
     * renders a confirm page — the actual change is the POST, so a mail scanner or link prefetch
     * cannot consume the token and silently change the login identifier.
     */
    public function confirmEmailForm(Request $request, string $token): View|RedirectResponse|InertiaResponse
    {
        $pending = $this->pendingEmailChange($token);
        if ($pending === null) {
            return redirect()->route('login')->with('status', __('This email-change link is no longer valid.'));
        }

        if ($wrongMember = $this->rejectIfDifferentMember($pending)) {
            return $wrongMember;
        }

        // A token landing from mail, reachable logged-in or out — the Modern page is auth-shell
        // (guest-safe), not member chrome.
        if (SurfaceResolver::resolve($request, 'member') === SurfaceResolver::MODERN) {
            return Inertia::render('auth/email-change-confirm', ['token' => $token, 'newEmail' => $pending->new_email]);
        }

        // Rendered in the Classic shell. The body class tracks the actual auth state so it matches the
        // shell's auth-driven nav/banner: the logged-in subject gets secure_page + member nav, a guest
        // gets the pre-login insecure_page + guest nav — each a combination the OpenPNE 3 skin styles.
        // (A different logged-in member was turned away above, so "logged in" here means the subject.)
        return view('member.email-change-confirm', ['token' => $token, 'newEmail' => $pending->new_email])
            ->with('pageId', 'page_member_emailChangeConfirm')
            ->with('pageClass', auth()->check() ? 'secure_page' : 'insecure_page');
    }

    public function confirmEmail(Request $request, string $token, ConfirmEmailChange $confirm): RedirectResponse
    {
        $pending = $this->pendingEmailChange($token);
        if ($pending === null) {
            return redirect()->route('login')->with('status', __('This email-change link is no longer valid.'));
        }

        if ($wrongMember = $this->rejectIfDifferentMember($pending)) {
            return $wrongMember;
        }

        // The address was free when the change was requested, but may have been claimed since (admin
        // creation, a concurrent change). Check up front (case-insensitive, like the request step), and
        // catch the members.email unique violation at commit as the final TOCTOU guard. Either way the
        // dead token is burned.
        if (Member::whereRaw('lower(email) = ?', [$pending->new_email])->exists()) {
            EmailChangeRequest::whereKey($pending->getKey())->delete();

            return redirect()->route('login')->with('status', __('That email address is no longer available.'));
        }

        // The login identifier before the swap; both addresses are the subject of the change.
        $oldEmail = Member::whereKey($pending->member_id)->value('email');

        try {
            $member = $confirm($pending);
        } catch (QueryException) {
            EmailChangeRequest::whereKey($pending->getKey())->delete();

            return redirect()->route('login')->with('status', __('That email address is no longer available.'));
        }

        // A concurrent cancel (or password-change purge) voided the pending change between the lookup
        // above and the commit — the login identifier was not touched, so surface it as a dead link.
        if ($member === null) {
            return redirect()->route('login')->with('status', __('This email-change link is no longer valid.'));
        }

        SecurityLog::event('email.changed', [
            'guard' => 'member',
            'member_id' => $member->getKey(),
            'old_email' => $oldEmail,
            'new_email' => $member->email,
        ]);

        // OWASP: changing the login identifier should drop the member's other devices. remember_token
        // is rotated in the commit (kills remember-me cookies everywhere); the session purge is
        // SessionRevocation's. An email change does not rotate the password hash, so auth.session
        // alone would not evict other sessions on a non-database driver; this contract assumes
        // database sessions (as withdrawal/reset do).
        SessionRevocation::purgeMemberSessions((int) $member->getKey());

        // Only the changed member or a logged-out visitor reaches here (a different logged-in member is
        // turned away above). If this is the changed member's own session, end it too, then everyone
        // signs in afresh with the new address.
        if (Auth::guard('member')->id() === $member->getKey()) {
            Auth::guard('member')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()->route('login')
            ->with('status', __('Your email address has been changed. Please sign in with your new address.'));
    }

    /**
     * Token-gated landing for the cancel link in the old-address notice. Public and no member match:
     * the cancel token proves control of the old address, and cancelling only voids a pending change,
     * so anyone holding the link may do it. The cancel is the POST below, so a mail scanner / prefetch
     * of this GET cannot void the change.
     */
    public function cancelEmailForm(Request $request, string $token): View|RedirectResponse|InertiaResponse
    {
        $pending = $this->pendingEmailChangeByCancelToken($token);
        if ($pending === null) {
            return redirect()->route('login')->with('status', __('This email-change link is no longer valid.'));
        }

        // Same auth-shell reasoning as confirmEmailForm: a guest-reachable token landing from mail.
        if (SurfaceResolver::resolve($request, 'member') === SurfaceResolver::MODERN) {
            return Inertia::render('auth/email-change-cancel', ['token' => $token, 'newEmail' => $pending->new_email]);
        }

        return view('member.email-change-cancel', ['token' => $token, 'newEmail' => $pending->new_email])
            ->with('pageId', 'page_member_emailChangeCancel')
            ->with('pageClass', auth()->check() ? 'secure_page' : 'insecure_page');
    }

    public function cancelEmail(string $token, CancelEmailChange $cancel): RedirectResponse
    {
        // A gone/expired row is already not pending — the cancel goal is met either way, so this is a
        // no-op success rather than an error.
        $pending = $this->pendingEmailChangeByCancelToken($token);
        if ($pending !== null) {
            $cancel($pending);
            SecurityLog::event('email.change_cancelled', [
                'guard' => 'member',
                'member_id' => $pending->member_id,
                'new_email' => $pending->new_email,
            ]);
        }

        return redirect()->route('login')->with('status', __('The email-address change has been cancelled.'));
    }

    /**
     * A confirmation link is the changed member's action; completing it while logged in as a DIFFERENT
     * member is an incoherent state (and would surface that member's identity in the shell). Turn them
     * away with a clear message — they can sign out and reopen the link. A guest, or the member
     * themselves, proceeds.
     */
    private function rejectIfDifferentMember(EmailChangeRequest $pending): ?RedirectResponse
    {
        $current = Auth::guard('member')->id();
        if ($current !== null && (int) $current !== (int) $pending->member_id) {
            return redirect()->route('home')
                ->with('status', __('This confirmation link is for a different account. Please sign out and open it again.'));
        }

        return null;
    }

    /** The live pending email change for a raw confirm token, or null when unknown or past its TTL. */
    private function pendingEmailChange(string $rawToken): ?EmailChangeRequest
    {
        return $this->livePendingEmailChange('token', $rawToken);
    }

    /** The live pending email change for a raw cancel token (old-address notice), or null. */
    private function pendingEmailChangeByCancelToken(string $rawToken): ?EmailChangeRequest
    {
        return $this->livePendingEmailChange('cancel_token', $rawToken);
    }

    /** The live pending row whose $column matches the hashed raw token, or null when unknown or expired. */
    private function livePendingEmailChange(string $column, string $rawToken): ?EmailChangeRequest
    {
        $row = EmailChangeRequest::where($column, hash('sha256', $rawToken))->first();
        if ($row === null || $row->created_at === null) {
            return null;
        }

        $ttl = (int) config('openpne.email_change.token_ttl_minutes');

        return $row->created_at->gt(now()->subMinutes($ttl)) ? $row : null;
    }
}
