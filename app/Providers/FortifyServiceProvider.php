<?php

namespace App\Providers;

use App\Actions\Fortify\AuthenticateMember;
use App\Actions\Fortify\CreateNewMember;
use App\Actions\Fortify\ResetMemberPassword;
use App\Actions\Fortify\Responses\NeutralPasswordResetLinkResponse;
use App\Captcha\Captcha;
use App\Compat\RouteParityRegistry;
use App\Features\Auth\LoginFormData;
use App\Features\Auth\LoginThrottle;
use App\Models\Member;
use App\Services\GadgetService;
use App\Services\SnsSettingService;
use App\Support\MarkdownText;
use App\Support\SnsSettingKey;
use App\Support\SurfaceResolver;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Enabling the two-factor feature would also auto-register Fortify's /user/two-factor-*
        // management routes, which bypass this app's management contract (inline current_password
        // re-auth + session revocation on factor change). Routes Fortify would register are instead
        // declared by hand in routes/web.php — only the ones this app uses.
        Fortify::ignoreRoutes();

        // Both outcomes of a forgot-password request resolve to the same neutral response, so the
        // endpoint cannot be used to enumerate which addresses have an account.
        $this->app->singleton(SuccessfulPasswordResetLinkRequestResponse::class, NeutralPasswordResetLinkResponse::class);
        $this->app->singleton(FailedPasswordResetLinkRequestResponse::class, NeutralPasswordResetLinkResponse::class);
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewMember::class);

        // A class-string is not a callable, so wrap the invokable action in a closure. With the
        // two-factor feature on, Fortify's login pipeline invokes this callback twice on a
        // successful non-two-factor login (RedirectIfTwoFactorAuthenticatable validates first,
        // AttemptToAuthenticate re-validates), so the resolved member is memoised per request —
        // otherwise every such login would burn a second bcrypt verification. A failure throws at
        // the first stage, so failures are still counted exactly once.
        Fortify::authenticateUsing(function (Request $request): ?Member {
            if ($request->attributes->has('login.member')) {
                return $request->attributes->get('login.member');
            }

            // After repeated failures from this IP, require the CAPTCHA before the credentials are even
            // checked — a soft escalation, never a lockout. A missing/invalid solve re-renders the form
            // with the widget; a bad solve is not counted as a login failure.
            if ($this->loginChallengeRequired($request) && ! $this->loginCaptchaSolved($request)) {
                throw ValidationException::withMessages(['altcha' => __('Captcha verification failed. Please try again.')]);
            }

            $member = app(AuthenticateMember::class)($request);

            $throttle = app(LoginThrottle::class);
            $member ? $throttle->clear((string) $request->ip()) : $throttle->recordFailure((string) $request->ip());

            $request->attributes->set('login.member', $member);

            return $member;
        });

        Fortify::resetUserPasswordsUsing(ResetMemberPassword::class);

        Fortify::loginView(function (Request $request) {
            $props = LoginFormData::for($request);
            $gadgets = app(GadgetService::class);

            return $this->screen($request, 'login', 'auth.login',
                // The message is a Modern-only slot; Classic carries the same kind of copy in its
                // login gadgets, so the settings tier is never read on the Classic path.
                fn () => Inertia::render('auth/login', $props + ['loginMessage' => $this->loginMessage()]),
                $props + [
                    'zones' => $gadgets->zones('login', viewer: $request->user()),
                    'layout' => $gadgets->layoutLetter('login'),
                ]);
        });
        Fortify::requestPasswordResetLinkView(fn (Request $request) => $this->screen(
            $request, 'password.request', 'auth.forgot-password',
            fn () => Inertia::render('auth/forgot-password'),
        ));
        Fortify::resetPasswordView(function (Request $request) {
            $props = ['email' => $request->string('email')->value(), 'token' => $request->route('token')];

            return $this->screen($request, 'password.reset', 'auth.reset-password',
                fn () => Inertia::render('auth/reset-password', $props), $props);
        });
        Fortify::twoFactorChallengeView(fn (Request $request) => $this->screen(
            $request, 'two-factor.login', 'auth.two-factor-challenge',
            fn () => Inertia::render('auth/two-factor-challenge'),
        ));

        // Auth-flow rate limiters (content/social write limiters live in AppServiceProvider).
        RateLimiter::for('login', function (Request $request) {
            // In the challenge phase the proof-of-work + single-use solution is the throttle, so a
            // solved challenge lifts the per-minute cap — otherwise the solved retry would be 429'd
            // before the credentials are checked, defeating the escalation. An unsolved request keeps
            // the per-(email, IP) limit.
            if ($this->loginChallengeRequired($request) && $this->loginCaptchaSolved($request)) {
                return Limit::none();
            }

            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        // Two-factor challenge submissions, keyed by the challenged member (login.id) alone —
        // vendor semantics. The adversary here already holds the password, so the guess budget
        // must be per account, not per (account, IP): an IP component would hand a distributed
        // attacker 5/min per IP. Unchallenged strays (no login.id) share one bucket and fail at
        // challengedUser() anyway.
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by('two-factor|'.$request->session()->get('login.id'));
        });

        // The four member two-factor management POSTs (enable/confirm/disable/regenerate) share one
        // budget; the GET render is left unthrottled (routes/web.php). Keyed by the authenticated
        // member, not the IP: the adversary at these endpoints already holds the session, so the
        // guess budget for the inline code/recovery proof must not scale with attacker IPs (same
        // reasoning as the challenge limiter).
        RateLimiter::for('mfa-manage', function (Request $request) {
            return Limit::perMinute(5)->by('mfa-manage|'.($request->user()?->getKey() ?? $request->ip()));
        });

        // Creating and deleting AI accounts, keyed by the owning member: each one is a member row
        // appearing in or vanishing from the site, so the budget belongs to whoever is spending it,
        // not to the address they are spending it from.
        RateLimiter::for('ai-manage', function (Request $request) {
            return Limit::perMinute(5)->by('ai-manage|'.($request->user()?->getKey() ?? $request->ip()));
        });

        // The admin-issued two-factor reset submit, keyed PER TOKEN, not per IP: a reset link is one
        // account's password-guess surface, so a distributed attacker must not be able to pool
        // 5/min-per-IP onto a single link. The raw token is hashed into the key so it never lands in the
        // cache store or a log. The per-IP spray cap across links is the route's throttle:30,1; a re-send
        // kills the old token, so its bucket simply goes stale.
        RateLimiter::for('mfa-reset', function (Request $request) {
            return Limit::perMinute(5)->by('mfa-reset|'.hash('sha256', (string) $request->route('token')));
        });

        // Two limits, whichever trips first: per-(email,ip) caps re-sends to one address; per-ip caps
        // using the endpoint to mail many *different* addresses (a registration-mail relay) — the
        // per-email key alone gives each address its own bucket, so it cannot bound that.
        RateLimiter::for('register-email', function (Request $request) {
            $email = Str::transliterate(Str::lower((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                Limit::perMinute(10)->by('register-ip|'.$request->ip()),
            ];
        });

        // Per-IP cap on the token-gated completion form (GET render + POST submit). The 40-char token
        // is the real gate; this just bounds blind guessing against the endpoint.
        RateLimiter::for('register-complete', fn (Request $request) => Limit::perMinute(10)->by('register-complete|'.$request->ip()));

        // Member invitation send, keyed by the inviting (authenticated) member, not the IP: per-(member,
        // email) caps re-inviting one address; per-member bounds using the form to mail many different
        // addresses (an invite-mail relay).
        RateLimiter::for('member-invite', function (Request $request) {
            $memberId = $request->user()?->getKey() ?? $request->ip();
            $email = Str::transliterate(Str::lower((string) $request->input('email')));

            return [
                Limit::perMinute(3)->by('member-invite|'.$memberId.'|'.$email),
                Limit::perMinute(10)->by('member-invite|'.$memberId),
            ];
        });

        // Email-change request, keyed by the authenticated member: bounds repeated change requests and
        // the enumeration surface of the new-address uniqueness check.
        RateLimiter::for('email-change', function (Request $request) {
            $memberId = $request->user()?->getKey() ?? $request->ip();

            return Limit::perMinute(5)->by('email-change|'.$memberId);
        });

        // Per-IP cap on the credential-bearing password endpoints (the broker only throttles
        // per-email, leaving relay/guessing across addresses open). Applied to every Fortify route
        // via config, so the GET forms and the separately-limited login route pass through unlimited.
        RateLimiter::for('password-reset', function (Request $request) {
            return in_array($request->route()?->getName(), ['password.email', 'password.update'], true)
                ? Limit::perMinute(5)->by('password-reset|'.$request->ip())
                : Limit::none();
        });
    }

    /**
     * The administrator's login screen message, as the stored Markdown plus its sanitized HTML, or
     * null when unset. Rendering goes through App\Support\MarkdownText — the same allowlist pipeline
     * a member body takes — so the operator's text reaches the client as safe HTML, never raw.
     *
     * @return array{body: string, bodyHtml: string}|null
     */
    private function loginMessage(): ?array
    {
        $body = (string) app(SnsSettingService::class)->get(SnsSettingKey::LoginMessage);

        if (trim($body) === '') {
            return null;
        }

        return ['body' => $body, 'bodyHtml' => MarkdownText::render($body)->toHtml()];
    }

    /** Whether this IP has failed enough logins that the form must now carry a CAPTCHA. */
    private function loginChallengeRequired(Request $request): bool
    {
        return app(Captcha::class)->enabled()
            && app(LoginThrottle::class)->challengeRequired((string) $request->ip());
    }

    /**
     * Whether the request carries a valid CAPTCHA solution. The solution is single-use, so it is
     * verified at most once per request and the result is memoised — the rate limiter and the
     * authentication callback both read it without consuming it twice. Load-bearing: with the
     * two-factor feature on, the login pipeline calls the authentication callback twice in one
     * request, so an unmemoised verify would spend the solution and fail its own second read.
     */
    private function loginCaptchaSolved(Request $request): bool
    {
        if (! $request->attributes->has('login.captcha')) {
            $payload = $request->input('altcha');
            $request->attributes->set('login.captcha', app(Captcha::class)->verify(is_string($payload) ? $payload : null));
        }

        return $request->attributes->get('login.captcha');
    }

    /**
     * Surface seam for Fortify's view callbacks: Classic returns the OpenPNE 3 Blade shell with the
     * route-parity body id and the pre-login `insecure_page` class; Modern returns the Inertia page.
     * `$bodyIdRoute` is the parity's Laravel route name, passed explicitly so the body id is keyed on
     * the contract, not on Fortify's view-callback request.
     */
    private function screen(Request $request, string $bodyIdRoute, string $classicView, Closure $modern, array $data = []): View|InertiaResponse
    {
        if (SurfaceResolver::resolve($request, 'auth') === SurfaceResolver::CLASSIC) {
            return view($classicView, $data)
                ->with('pageId', RouteParityRegistry::bodyId($bodyIdRoute))
                ->with('pageClass', 'insecure_page');
        }

        return $modern();
    }
}
