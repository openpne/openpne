<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\RedirectResponse;

/**
 * The bounce a guest gets from a page that needs a login: OpenPNE 3's notice plus the
 * intended-URL capture. The `auth` middleware produces it through the framework
 * (bootstrap/app.php `redirectGuestsTo`); a page that decides the gate itself — a web-public
 * screen a guest is not entitled to this time — produces it here, so both read the same.
 */
final class GuestLoginRedirect
{
    public static function response(): RedirectResponse
    {
        return redirect()->guest(self::target());
    }

    /** The login URL, after flashing the notice that goes with the bounce. */
    public static function target(): string
    {
        session()->flash('status', __('Please login to visit this page'));

        return route('login');
    }
}
