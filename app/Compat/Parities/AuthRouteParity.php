<?php

namespace App\Compat\Parities;

use App\Compat\CompatLevel as L;
use App\Compat\RouteMap;
use App\Compat\RouteParity;
use App\Compat\ScreenElement;
use App\Compat\ScreenStatus as S;

/**
 * The pre-login auth screens Fortify owns (password reset; registration added later). This is an
 * OpenPNE 4-native grouping, not an OpenPNE 3 module — openpne3Module() is null, so the audit does
 * not expect it in the route inventory. The OpenPNE 3 origins (the opAuthMailAddress plugin's
 * passwordRecovery/passwordRecoveryComplete actions) are not in the inventory either, so these are
 * native maps (no op3Route): they derive a faithful Classic body id via op3Module/op3Action without
 * binding to an inventory entry. The OpenPNE 3 URLs are kept reachable through compatRedirects().
 */
class AuthRouteParity extends RouteParity
{
    protected string $module = 'auth';

    public function openpne3Module(): ?string
    {
        return null;
    }

    public function maps(): array
    {
        return [
            // Password reset — Fortify serves /forgot-password and /reset-password/{token}.
            new RouteMap(null, null, 'password.request', 'GET', op3Action: 'passwordRecovery', op3Module: 'opAuthMailAddress'),
            new RouteMap(null, null, 'password.reset', 'GET', op3Action: 'passwordRecoveryComplete', op3Module: 'opAuthMailAddress'),
        ];
    }

    public function compatRedirects(): array
    {
        // OpenPNE 3 password-recovery URLs (the second is the one its mail emitted, with ?token=&id=).
        // Fortify's token scheme (email + path token) is incompatible with OpenPNE 3's (id + token),
        // so an in-flight OpenPNE 3 token cannot be honored — both entry points redirect to the
        // request form to restart the flow.
        return [
            '/opAuthMailAddress/passwordRecovery' => 'password.request',
            '/opAuthMailAddress/passwordRecoveryComplete' => 'password.request',
        ];
    }

    public function screens(): array
    {
        return [
            // passwordRecoverySuccess.php → resources/views/auth/forgot-password.blade.php
            'passwordRecovery' => [
                new ScreenElement('mail address input', L::One, S::Ported, 'opAuthMailAddressPasswordRecoveryForm', 'field name not preserved (email, Level 3)'),
                new ScreenElement('submit button', L::Two, S::Ported, 'op_include_form passwordRecovery'),
                new ScreenElement('sent confirmation message', L::Two, S::Ported, "flash 'Sent you a mail ...'", 'Fortify status flashed to session(status), rendered by the shell alertBox'),
            ],
            // passwordRecoveryCompleteSuccess.php → resources/views/auth/reset-password.blade.php
            'passwordRecoveryComplete' => [
                new ScreenElement('new password + confirmation inputs', L::One, S::Ported, 'opAuthMailAddressPasswordChangeForm'),
                new ScreenElement('explanatory body text', L::Three, S::Ported, "op_include_form body 'Please input your new password.'"),
                new ScreenElement('submit button', L::Two, S::Ported, 'op_include_form passowrdResetForm'),
            ],
        ];
    }
}
