<?php

namespace App\Features\Auth;

use App\Http\Controllers\Controller;
use App\Support\SecurityLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/** See docs/internals/security.md, "Member passkeys". */
class LoginCodeController extends Controller
{
    public function __construct(private readonly LoginCode $codes) {}

    public function create(Request $request): View|InertiaResponse|RedirectResponse
    {
        if ($this->codes->pending($request->session()) === null) {
            return redirect()->route('login');
        }

        return AuthScreen::render($request, 'login.code', 'auth.login-code', fn () => Inertia::render('auth/login-code'));
    }

    public function store(Request $request): Response
    {
        $slot = $this->codes->pending($request->session());

        if ($slot === null) {
            return $this->expired();
        }

        $code = $request->validate(['code' => ['required', 'string']])['code'];
        $member = $this->codes->verify($request->session(), $code);

        if ($member === null) {
            SecurityLog::event('login.code_failed', ['guard' => 'member', 'member_id' => $slot['member_id']]);

            return $this->codes->pending($request->session()) === null
                ? $this->expired()
                : back()->withErrors(['code' => __('The confirmation code is incorrect.')]);
        }

        Auth::guard('member')->login($member);
        $request->session()->regenerate();

        return app(TwoFactorLoginResponse::class)->toResponse($request);
    }

    public function resend(Request $request): RedirectResponse
    {
        $sent = $this->codes->reissue($request->session());

        if ($sent === null) {
            return $this->expired();
        }

        if (! $sent) {
            return back()->withErrors(['code' => __('Too many confirmation codes have been sent. Please try again later.')]);
        }

        return back()->with('status', __('A new confirmation code has been sent.'));
    }

    private function expired(): RedirectResponse
    {
        return redirect()->route('login')->withErrors([
            Fortify::username() => __('The confirmation code is no longer valid. Please sign in again.'),
        ]);
    }
}
