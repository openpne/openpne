<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Gadgets\GadgetKind;

/**
 * The login form (OpenPNE 3 default/loginFormBox). Login page only and public; its component reuses
 * the existing login form partial rather than re-implementing the CAPTCHA/registration/error
 * behaviour.
 */
class LoginFormGadget extends GadgetKind
{
    public function name(): string
    {
        return 'loginForm';
    }

    public function contexts(): array
    {
        return ['login'];
    }

    public function component(): string
    {
        return 'gadget.login-form';
    }

    public function viewablePrivilege(string $context): int
    {
        return self::ANYONE;
    }
}
