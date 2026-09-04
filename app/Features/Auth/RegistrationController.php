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
        // A tripped bot filter only skips issuing the token: the same neutral screen answers either
        // way, so a bot cannot tell it was caught.
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

        // The address may have been claimed since issuance, and the form has no email field to show a
        // unique failure on, so each layer that catches it consumes the dead token and redirects to
        // sign in.
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
     * Closed 404s before the lookup, so a known and an unknown token are indistinguishable while the
     * route is off. The live token is then checked against its own origin, so an admin invite completes
     * even in admin_only mode while a self or member link does not.
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
        // A null lookup cannot tell a spent token from an expired or unknown one, so the message covers
        // both, and only open mode can offer the self-service entry as the way to a fresh link.
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
