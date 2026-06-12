<?php

namespace App\Features\Auth;

use App\Captcha\Captcha;
use App\Compat\RouteParityRegistry;
use App\Features\Auth\Actions\CompleteRegistration;
use App\Features\Auth\Actions\IssueRegistrationToken;
use App\Features\Profile\Queries\RegistrationFields;
use App\Features\Profile\Serializers\ProfileFormSerializer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterEmailRequest;
use App\Models\Member;
use App\Models\RegistrationToken;
use App\Support\SurfaceResolver;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Multi-stage member registration (OpenPNE 3's email-confirmation flow). The email-entry half takes an
 * address, mails a single-use token, and shows a neutral confirmation. The completion half renders the
 * token-gated form (name/password/profile) and creates the member on submit; the token's email is
 * authoritative throughout, so the address is never re-entered.
 */
class RegistrationController extends Controller
{
    public function requestForm(Request $request, SpamTrap $trap, Captcha $captcha): View|InertiaResponse
    {
        $trap->arm($request);

        // Render the widget iff the resolved driver actually enforces a challenge, so the UI can never
        // show a captcha the server is ignoring (e.g. enabled but a non-altcha driver → NullCaptcha).
        return $this->screen($request, 'auth.register-email', 'auth/register-email', [
            'honeypot' => SpamTrap::HONEYPOT,
            'captcha' => $captcha->enabled(),
            'challengeUrl' => route('altcha.challenge'),
        ]);
    }

    public function request(RegisterEmailRequest $request, IssueRegistrationToken $issue, SpamTrap $trap): RedirectResponse
    {
        // Always lands on the same neutral screen — whether or not the address is already a member,
        // and whether or not the bot filters passed. A tripped filter just skips issuing the token,
        // so a bot cannot tell it was caught.
        if ($trap->passes($request)) {
            $issue($request->validated()['email']);
        }

        return redirect()->route('register.sent');
    }

    public function sent(Request $request): View|InertiaResponse
    {
        return $this->screen($request, 'auth.register-sent', 'auth/register-sent');
    }

    public function form(Request $request, string $token, RegistrationFields $fields): View|InertiaResponse|RedirectResponse
    {
        $pending = $this->resolveForCompletion($token);
        if ($pending instanceof RedirectResponse) {
            return $pending;
        }

        $lang = $this->translationLang();
        $list = $fields();

        if (SurfaceResolver::resolve($request, 'auth') === SurfaceResolver::CLASSIC) {
            return view('auth.register-complete', ['token' => $token, 'email' => $pending->email, 'fields' => $list, 'lang' => $lang])
                ->with('pageId', RouteParityRegistry::bodyId('register.form'))
                ->with('pageClass', 'insecure_page');
        }

        return Inertia::render('auth/register-complete', [
            'token' => $token,
            'email' => $pending->email,
            'fields' => ProfileFormSerializer::fields($list, $lang),
        ]);
    }

    public function register(Request $request, string $token, CompleteRegistration $complete): RedirectResponse
    {
        $pending = $this->resolveForCompletion($token);
        if ($pending instanceof RedirectResponse) {
            return $pending;
        }

        // The token's address was free when issued, but a member may have claimed it since (admin
        // creation, or a concurrent completion). The form has no email field, so a unique-email
        // failure has nowhere to show — every such case consumes the now-dead token and sends them to
        // sign in. Caught at three layers as the window narrows: this up-front check, the create's
        // unique rule (ValidationException on `email`), and the insert itself (QueryException).
        if (Member::whereRaw('lower(email) = ?', [$pending->email])->exists()) {
            return $this->addressClaimed($pending);
        }

        try {
            $member = $complete($pending, $request->all());
        } catch (ValidationException $e) {
            if ($e->validator->errors()->has('email')) {
                return $this->addressClaimed($pending);
            }

            throw $e; // name / password / profile errors → back to the form, token kept
        } catch (QueryException) {
            return $this->addressClaimed($pending);
        }

        Auth::login($member);
        $request->session()->regenerate();

        return redirect()->intended(route('home'))->with('status', __('Your account is ready.'));
    }

    /**
     * Resolves a raw token for completion, applying the mode gate, or returns a redirect to send the
     * visitor away. The completion route is no longer open-only (an invite must be completable in
     * invite/admin_only mode), so the mode is re-checked here against the token's origin:
     *   1. closed → 404 before the lookup, so the route is wholly off (known and unknown tokens alike).
     *   2. unknown/expired → the expired() redirect (only reached when not closed).
     *   3. a live token whose source the current mode no longer issues (e.g. a self token in invite) → 404.
     */
    private function resolveForCompletion(string $token): RegistrationToken|RedirectResponse
    {
        abort_if(RegistrationMode::current() === RegistrationMode::Closed, 404);

        $pending = $this->pending($token);
        if ($pending === null) {
            return $this->expired();
        }

        abort_unless(RegistrationMode::current()->allows($pending->source), 404);

        return $pending;
    }

    /** The live pending registration for a raw token, or null when it is unknown or past its TTL. */
    private function pending(string $rawToken): ?RegistrationToken
    {
        // Exact lookup on the stored hash via the unique index — never a prefix/LIKE match, so a
        // partial token cannot probe the space.
        $row = RegistrationToken::where('token', hash('sha256', $rawToken))->first();
        if ($row === null || $row->created_at === null) {
            return null;
        }

        $ttl = (int) config('openpne.registration.token_ttl_minutes');

        return $row->created_at->gt(now()->subMinutes($ttl)) ? $row : null;
    }

    private function expired(): RedirectResponse
    {
        // A null lookup cannot tell a spent token (registration already finished) from an expired or
        // unknown one, so the message has to serve both: sign in if you are already registered,
        // otherwise get a fresh link. Where to send them depends on the mode: only open exposes the
        // self-service entry, so the "enter your email again" path is offered there; the invite modes
        // send the visitor to sign-in (their fresh link comes from a new invitation, not self-service).
        if (RegistrationMode::current()->allowsOpenRegistration()) {
            return redirect()->route('register')
                ->with('status', __('This registration link is no longer valid. If you have already registered, please sign in; otherwise, enter your email again to get a new link.'));
        }

        return redirect()->route('login')
            ->with('status', __('This registration link is no longer valid. If you have already registered, please sign in; otherwise, ask to be invited again.'));
    }

    /** The token's address now belongs to a member: burn the dead token and send them to sign in. */
    private function addressClaimed(RegistrationToken $pending): RedirectResponse
    {
        $pending->delete();

        return redirect()->route('login')
            ->with('status', __('This address is already registered. Please sign in.'));
    }

    private function translationLang(): string
    {
        return app()->getLocale() === 'ja' ? 'ja_JP' : 'en';
    }

    /**
     * Pre-login surface seam: Classic returns the OpenPNE 3 Blade shell with the route-parity body
     * id and the insecure_page class; Modern returns the Inertia page.
     *
     * @param  array<string, mixed>  $data
     */
    private function screen(Request $request, string $classicView, string $modernComponent, array $data = []): View|InertiaResponse
    {
        if (SurfaceResolver::resolve($request, 'auth') === SurfaceResolver::CLASSIC) {
            return view($classicView, $data)
                ->with('pageId', RouteParityRegistry::bodyId($request->route()->getName()))
                ->with('pageClass', 'insecure_page');
        }

        return Inertia::render($modernComponent, $data);
    }
}
