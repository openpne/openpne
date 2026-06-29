<?php

namespace App\Features\Member;

use App\Compat\RouteParityRegistry;
use App\Features\Diary\DiaryVisibility;
use App\Features\Member\Actions\WithdrawMember;
use App\Features\Member\Serializers\MemberConfigSerializer;
use App\Features\Profile\AgeVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UpdateAgeVisibilityRequest;
use App\Http\Requests\Member\UpdateDiaryDefaultRequest;
use App\Http\Requests\Member\UpdatePasswordRequest;
use App\Http\Requests\Member\UpdatePreferredSurfaceRequest;
use App\Http\Requests\Member\WithdrawalRequest;
use App\Models\Member;
use App\Support\PreferenceKey;
use App\Support\Surface;
use App\Support\SurfaceResolver;
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

        return $this->savedRedirect($request, MemberConfigCategory::Password);
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
