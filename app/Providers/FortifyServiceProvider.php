<?php

namespace App\Providers;

use App\Actions\Fortify\AuthenticateMember;
use App\Actions\Fortify\CreateNewMember;
use App\Actions\Fortify\ResetMemberPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewMember::class);

        // A class-string is not a callable, so wrap the invokable action in a closure.
        Fortify::authenticateUsing(fn (Request $request) => app(AuthenticateMember::class)($request));

        Fortify::resetUserPasswordsUsing(ResetMemberPassword::class);

        Fortify::loginView(fn () => Inertia::render('auth/login'));
        Fortify::registerView(fn () => Inertia::render('auth/register'));
        Fortify::requestPasswordResetLinkView(fn () => Inertia::render('auth/forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->string('email')->value(),
            'token' => $request->route('token'),
        ]));

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
