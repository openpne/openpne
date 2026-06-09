<?php

namespace App\Features\Auth;

use App\Captcha\Captcha;
use App\Compat\RouteParityRegistry;
use App\Features\Auth\Actions\IssueRegistrationToken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterEmailRequest;
use App\Support\SurfaceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Multi-stage member registration (OpenPNE 3's email-confirmation flow). This PR covers the
 * email-entry half: enter an address → a token is mailed → a neutral "check your mail" screen.
 * The token-gated form and completion (GET/POST /register/{token}) land in the next PR.
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
