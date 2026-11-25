<?php

namespace App\Http\Controllers\Admin;

use App\Auth\AdminPasswords;
use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Support\SecurityLog;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Rules\Password;

/**
 * Every dead link, whatever the reason, gets the same page, and a refused submit is answered in
 * place, because a redirect would flash the input into the session (docs/internals/security.md,
 * "Administrator password links").
 */
class PasswordSetupController extends Controller
{
    public function __construct(private readonly AdminPasswords $passwords) {}

    public function show(?string $token = null): Response
    {
        $admin = $this->passwords->setupLinkOwner((string) $token);

        return $admin === null ? $this->unusable() : $this->form($admin, (string) $token, new MessageBag);
    }

    public function store(Request $request, ?string $token = null): Response|RedirectResponse
    {
        $token = (string) $token;
        $admin = $this->passwords->setupLinkOwner($token);
        if ($admin === null) {
            return $this->unusable();
        }

        // The username is the stored one, so the context-word rule never checks a name the browser sent.
        $validator = Validator::make(
            ['username' => $admin->username, 'password' => $request->input('password'), 'password_confirmation' => $request->input('password_confirmation')],
            ['password' => ['required', 'string', Password::default(), 'confirmed']],
        );
        if ($validator->fails()) {
            return $this->form($admin, $token, $validator->errors(), 422);
        }

        $admin = $this->passwords->consumeSetupLink($token, $validator->validated()['password']);
        if ($admin === null) {
            return $this->unusable();
        }

        SecurityLog::event('password.changed', ['guard' => 'admin', 'username' => $admin->username, 'via' => 'link']);

        // Not the guard's logout(): an administrator signed in here may be another one, whose
        // remember token that would rotate.
        $request->session()->invalidate();
        Notification::make()->success()->title(__('Your password is set. Sign in with it.'))->send();

        return redirect()->to(filament()->getLoginUrl());
    }

    private function form(AdminUser $admin, string $token, MessageBag $errors, int $status = 200): Response
    {
        return response()->view('filament.password-setup', [
            'username' => $admin->username,
            'action' => route('admin.password_setup.store', ['token' => $token]),
            'messages' => $errors,
        ], $status);
    }

    private function unusable(): Response
    {
        return response()->view('filament.password-setup', ['username' => null], 404);
    }
}
