<?php

namespace App\Features\Member;

use App\Compat\RouteParityRegistry;
use App\Features\Diary\DiaryVisibility;
use App\Features\Member\Actions\ConfirmEmailChange;
use App\Features\Member\Actions\RequestEmailChange;
use App\Features\Member\Actions\WithdrawMember;
use App\Features\Member\Serializers\MemberConfigSerializer;
use App\Features\Profile\AgeVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\RequestEmailChangeRequest;
use App\Http\Requests\Member\UpdateAgeVisibilityRequest;
use App\Http\Requests\Member\UpdateDiaryDefaultRequest;
use App\Http\Requests\Member\UpdatePasswordRequest;
use App\Http\Requests\Member\UpdatePreferredSurfaceRequest;
use App\Http\Requests\Member\WithdrawalRequest;
use App\Models\EmailChangeRequest;
use App\Models\Member;
use App\Support\PreferenceKey;
use App\Support\Surface;
use App\Support\SurfaceResolver;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The member's own settings page (OpenPNE 3 member/config), Classic + Modern. Each section is its
 * own submit so saving one never rewrites another — in particular, the diary section shows the
 * read-time clamped default (DiaryVisibility::defaultFor), which must not be written back on an
 * unrelated change (it would collapse a stored Open once web-public is off).
 */
class MemberConfigController extends Controller
{
    public function show(Request $request): View|InertiaResponse|RedirectResponse
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

        return $this->respondWith($request, [
            // Classic paginates by ?category= (OpenPNE 3 member/config). An absent / non-string /
            // unrecognized value resolves to null = the "select an item" landing (no 404 — OpenPNE 4
            // keeps unknown categories renderable; only accessBlock redirects, handled above). Resolved
            // inside the Classic closure so the Modern single page never sees ?category=.
            SurfaceResolver::CLASSIC => function () use ($viewer, $currentSurface, $request) {
                $raw = $request->query('category');

                return view('member.config', [
                    'category' => is_string($raw) ? MemberConfigCategory::tryFrom($raw) : null,
                    'diaryDefault' => DiaryVisibility::defaultFor($viewer),
                    'diaryOptions' => DiaryVisibility::options(),
                    'ageDefault' => AgeVisibility::defaultFor($viewer),
                    'ageOptions' => AgeVisibility::options(),
                    'locale' => app()->getLocale(),
                    'currentSurface' => $currentSurface,
                    'email' => $viewer->email,
                ]);
            },
            SurfaceResolver::MODERN => fn () => Inertia::render('member/config', [
                'form' => MemberConfigSerializer::form($viewer, $currentSurface),
            ]),
        ]);
    }

    public function updateDiary(UpdateDiaryDefaultRequest $request): RedirectResponse
    {
        $value = PreferenceKey::DiaryDefaultVisibility->coerce($request->validated('diary_default_visibility'));
        $this->viewer()->setPreference(PreferenceKey::DiaryDefaultVisibility, $value);

        return $this->savedRedirect($request, MemberConfigCategory::Diary);
    }

    public function updateAge(UpdateAgeVisibilityRequest $request): RedirectResponse
    {
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

        // Compensating control for the notify-only email change: a stolen-password attacker could have
        // requested an email change, so a password change must void any pending one — otherwise the
        // attacker still holds a live confirmation token for the new address.
        EmailChangeRequest::where('member_id', $viewer->getKey())->delete();

        return $this->savedRedirect($request, MemberConfigCategory::Password);
    }

    public function updateEmail(RequestEmailChangeRequest $request, RequestEmailChange $requestChange): RedirectResponse
    {
        $requestChange($this->viewer(), $request->validated('new_email'));

        // members.email is unchanged until confirmation; tell the member to open the link just mailed.
        $name = SurfaceResolver::redirectName($request, 'member.config');
        $params = $name === 'member.config' ? ['category' => MemberConfigCategory::Email->value] : [];

        return redirect()->route($name, $params)
            ->with('status', __('We sent a confirmation link to your new email address. Open it to finish the change.'));
    }

    /**
     * Confirmation landing for the emailed link (token-gated, reachable logged-in or out). GET only
     * renders a confirm page — the actual change is the POST, so a mail scanner or link prefetch
     * cannot consume the token and silently change the login identifier.
     */
    public function confirmEmailForm(string $token): View|RedirectResponse
    {
        $pending = $this->pendingEmailChange($token);
        if ($pending === null) {
            return redirect()->route('login')->with('status', __('This email-change link is no longer valid.'));
        }

        // Rendered in the Classic shell (insecure_page, like register-complete) — reachable pre-login.
        return view('member.email-change-confirm', ['token' => $token, 'newEmail' => $pending->new_email])
            ->with('pageId', 'page_member_emailChangeConfirm')
            ->with('pageClass', 'insecure_page');
    }

    public function confirmEmail(Request $request, string $token, ConfirmEmailChange $confirm): RedirectResponse
    {
        $pending = $this->pendingEmailChange($token);
        if ($pending === null) {
            return redirect()->route('login')->with('status', __('This email-change link is no longer valid.'));
        }

        // The address was free when the change was requested, but may have been claimed since (admin
        // creation, a concurrent change). Check up front (case-insensitive, like the request step), and
        // catch the members.email unique violation at commit as the final TOCTOU guard. Either way the
        // dead token is burned.
        if (Member::whereRaw('lower(email) = ?', [$pending->new_email])->exists()) {
            EmailChangeRequest::whereKey($pending->getKey())->delete();

            return redirect()->route('login')->with('status', __('That email address is no longer available.'));
        }

        try {
            $member = $confirm($pending);
        } catch (QueryException) {
            EmailChangeRequest::whereKey($pending->getKey())->delete();

            return redirect()->route('login')->with('status', __('That email address is no longer available.'));
        }

        // OWASP: the login identifier changed, so drop every device. remember_token was rotated in the
        // commit; purge the member's database sessions (honoring the configured table) and reset the
        // current session, then send them to sign in with the new address.
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $member->getKey())->delete();
        }
        if (Auth::guard('member')->id() === $member->getKey()) {
            Auth::guard('member')->logout();
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')
            ->with('status', __('Your email address has been changed. Please sign in with your new address.'));
    }

    /** The live pending email change for a raw token, or null when it is unknown or past its TTL. */
    private function pendingEmailChange(string $rawToken): ?EmailChangeRequest
    {
        $row = EmailChangeRequest::where('token', hash('sha256', $rawToken))->first();
        if ($row === null || $row->created_at === null) {
            return null;
        }

        $ttl = (int) config('openpne.email_change.token_ttl_minutes');

        return $row->created_at->gt(now()->subMinutes($ttl)) ? $row : null;
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
        // the member leaves its session rows behind. On the database driver purge them outright, honoring
        // the configured session table (mirror ResetMemberPassword); other drivers keep no central store,
        // but a deleted member can't re-authenticate regardless. Then reset the current session.
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $member->getKey())
                ->delete();
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->flash('status', __('Your account has been deleted.'));

        $target = route('login');

        return $request->hasHeader('X-Inertia') ? Inertia::location($target) : redirect($target);
    }

    public function updateSurface(UpdatePreferredSurfaceRequest $request): Response
    {
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

        // Land on the chosen surface's own config page so the whole shell re-renders there. An
        // explicit /m/* URL is top of SurfaceResolver's order, so a Classic choice MUST leave /m/*
        // for the canonical route, or the page would stay Modern.
        $target = $chosen === Surface::Modern
            ? route('member.modern.config')
            : route('member.config', ['category' => MemberConfigCategory::General->value]);

        return $request->hasHeader('X-Inertia') ? Inertia::location($target) : redirect($target);
    }

    /**
     * Redirect back to the just-saved section: the Classic category page (`?category=`), or the bare
     * Modern config page on Modern (single page, no category). Gating the param to the Classic route
     * keeps the Modern redirect category-free.
     */
    private function savedRedirect(Request $request, MemberConfigCategory $category): RedirectResponse
    {
        $name = SurfaceResolver::redirectName($request, 'member.config');
        $params = $name === 'member.config' ? ['category' => $category->value] : [];

        return redirect()->route($name, $params)->with('status', __('Settings updated.'));
    }

    private function viewer(): Member
    {
        $viewer = auth()->user();
        assert($viewer instanceof Member);

        return $viewer;
    }

    /**
     * @param  array{classic: callable(): (View|InertiaResponse), modern: callable(): (View|InertiaResponse)}  $responders
     */
    private function respondWith(Request $request, array $responders): View|InertiaResponse
    {
        $response = $responders[SurfaceResolver::resolve($request, 'member')]();

        if ($response instanceof View) {
            $name = SurfaceResolver::canonicalName($request->route()->getName());
            $response->with('pageId', RouteParityRegistry::bodyId($name));
        }

        return $response;
    }
}
