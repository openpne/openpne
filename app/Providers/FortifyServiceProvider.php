<?php

namespace App\Providers;

use App\Actions\Fortify\AuthenticateMember;
use App\Actions\Fortify\CreateNewMember;
use App\Actions\Fortify\ResetMemberPassword;
use App\Actions\Fortify\Responses\NeutralPasswordResetLinkResponse;
use App\Compat\RouteParityRegistry;
use App\Support\SurfaceResolver;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Both outcomes of a forgot-password request resolve to the same neutral response, so the
        // endpoint cannot be used to enumerate which addresses have an account.
        $this->app->singleton(SuccessfulPasswordResetLinkRequestResponse::class, NeutralPasswordResetLinkResponse::class);
        $this->app->singleton(FailedPasswordResetLinkRequestResponse::class, NeutralPasswordResetLinkResponse::class);
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewMember::class);

        // A class-string is not a callable, so wrap the invokable action in a closure.
        Fortify::authenticateUsing(fn (Request $request) => app(AuthenticateMember::class)($request));

        Fortify::resetUserPasswordsUsing(ResetMemberPassword::class);

        Fortify::loginView(fn (Request $request) => $this->screen(
            $request, 'login', 'auth.login',
            fn () => Inertia::render('auth/login'),
        ));
        Fortify::requestPasswordResetLinkView(fn (Request $request) => $this->screen(
            $request, 'password.request', 'auth.forgot-password',
            fn () => Inertia::render('auth/forgot-password'),
        ));
        Fortify::resetPasswordView(function (Request $request) {
            $props = ['email' => $request->string('email')->value(), 'token' => $request->route('token')];

            return $this->screen($request, 'password.reset', 'auth.reset-password',
                fn () => Inertia::render('auth/reset-password', $props), $props);
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
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
