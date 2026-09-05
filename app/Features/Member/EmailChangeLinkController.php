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

/** Guest-reachable and token-gated, since the member may open the link on another device. */
class EmailChangeLinkController extends Controller
{
    /** GET only renders: a mail scanner or link prefetch must not consume the token. */
    public function confirmEmailForm(Request $request, string $token): View|RedirectResponse|InertiaResponse
    {
        $pending = $this->pendingEmailChange($token);
        if ($pending === null) {
            return redirect()->route('login')->with('status', __('This email-change link is no longer valid.'));
        }

        if ($wrongMember = $this->rejectIfDifferentMember($pending)) {
            return $wrongMember;
        }

        // The Modern page is the auth shell, since this landing must be guest-safe.
        if (SurfaceResolver::resolve($request, 'member') === SurfaceResolver::MODERN) {
            return Inertia::render('auth/email-change-confirm', ['token' => $token, 'newEmail' => $pending->new_email]);
        }

        // The body class tracks the actual auth state, so the OpenPNE 3 skin styles a page the shell's
        // nav matches.
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

        // The address may have been claimed since the request; the unique violation at commit is the
        // final guard, and either way the dead token is burned.
        if (Member::whereRaw('lower(email) = ?', [$pending->new_email])->exists()) {
            EmailChangeRequest::whereKey($pending->getKey())->delete();

            return redirect()->route('login')->with('status', __('That email address is no longer available.'));
        }

        // Read before the swap: the log names both addresses.
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

        // An email change does not rotate the password hash, so evicting the member's other sessions
        // assumes the database session driver.
        SessionRevocation::purgeMemberSessions((int) $member->getKey());

        // The purge above already dropped this session's row; logging out drops the stale guard state and cookie.
        if (Auth::guard('member')->id() === $member->getKey()) {
            Auth::guard('member')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()->route('login')
            ->with('status', __('Your email address has been changed. Please sign in with your new address.'));
    }

    /**
     * No member match: the cancel token proves control of the old address, and cancelling only voids a
     * pending change. GET only renders: a prefetch must not void the change.
     */
    public function cancelEmailForm(Request $request, string $token): View|RedirectResponse|InertiaResponse
    {
        $pending = $this->pendingEmailChangeByCancelToken($token);
        if ($pending === null) {
            return redirect()->route('login')->with('status', __('This email-change link is no longer valid.'));
        }

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

    /** A guest or the subject proceeds; a different logged-in member is turned away. */
    private function rejectIfDifferentMember(EmailChangeRequest $pending): ?RedirectResponse
    {
        $current = Auth::guard('member')->id();
        if ($current !== null && (int) $current !== (int) $pending->member_id) {
            return redirect()->route('home')
                ->with('status', __('This confirmation link is for a different account. Please sign out and open it again.'));
        }

        return null;
    }

    private function pendingEmailChange(string $rawToken): ?EmailChangeRequest
    {
        return $this->livePendingEmailChange('token', $rawToken);
    }

    private function pendingEmailChangeByCancelToken(string $rawToken): ?EmailChangeRequest
    {
        return $this->livePendingEmailChange('cancel_token', $rawToken);
    }

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
