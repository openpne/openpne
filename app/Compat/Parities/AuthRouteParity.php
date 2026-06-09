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

            // Registration email-entry — OpenPNE 3 opAuthMailAddress/requestRegisterURL rendered both
            // the input form and the "sent" success under one page id, so both routes share it.
            new RouteMap(null, null, 'register', 'GET', op3Action: 'requestRegisterURL', op3Module: 'opAuthMailAddress'),
            new RouteMap(null, null, 'register.sent', 'GET', op3Action: 'requestRegisterURL', op3Module: 'opAuthMailAddress'),

            // Registration completion — the token-gated account form. OpenPNE 3's mailed link landed on
            // opAuthMailAddress/register, which forwarded to the member/registerInput form; OpenPNE 4
            // serves the form directly, so the body id follows the rendered screen (member/registerInput).
            new RouteMap(null, null, 'register.form', 'GET', op3Action: 'registerInput', op3Module: 'member'),
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
            // requestRegisterURLInput.php + requestRegisterURLSuccess.php → register-email / register-sent
            'requestRegisterURL' => [
                new ScreenElement('mail address input', L::One, S::Ported, 'opRequestRegisterURLForm', 'field name not preserved (email, Level 3)'),
                new ScreenElement('submit button', L::Two, S::Ported, 'op_include_form requestRegisterURL'),
                new ScreenElement('"invitation sent" confirmation screen', L::Two, S::Ported, 'requestRegisterURLSuccess.php', 'register.sent; enumeration-safe (shown whether or not the address is a member)'),
                new ScreenElement('CAPTCHA', L::Three, S::Ported, 'is_use_captcha', 'self-hosted ALTCHA proof-of-work replaces the OpenPNE 3 image CAPTCHA'),
            ],
            // member/registerInput → resources/views/auth/register-complete.blade.php. The address is
            // fixed by the token (shown, not re-entered); name + password + the registration profile
            // fields are collected, then the member is created and logged in.
            'registerInput' => [
                new ScreenElement('mail address (read-only)', L::Two, S::Ported, 'member/registerInput', 'authoritative from the token, not an input'),
                new ScreenElement('nickname input', L::One, S::Ported, 'sfWidgetFormInputText nickname', 'field name not preserved (name, Level 3)'),
                new ScreenElement('password + confirmation inputs', L::One, S::Ported, 'sfWidgetFormInputPassword password / password_confirm'),
                new ScreenElement('registration profile fields', L::One, S::Ported, 'op_include_form member is_disp_regist'),
                new ScreenElement('submit button', L::Two, S::Ported, 'op_include_form member/registerInput'),
            ],
        ];
    }
}
