<?php

namespace App\Filament\Auth;

use Filament\Auth\Http\Responses\Contracts\LogoutResponse as Responsable;
use Illuminate\Http\RedirectResponse;

/** Filament's own response sends a panel without a login page back to the panel, which bounces a guest to the login with a "please log in" notice. */
class LogoutResponse implements Responsable
{
    public function toResponse($request): RedirectResponse
    {
        return redirect()->route('login');
    }
}
