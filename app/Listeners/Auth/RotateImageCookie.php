<?php

namespace App\Listeners\Auth;

use App\Files\VariantUrlSigner;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/** Member guard only: an admin signing in or out must not end the member's signed URLs (docs/internals/images.md, "The fast path"). */
class RotateImageCookie
{
    public function __construct(private readonly VariantUrlSigner $signer) {}

    public function handleLogin(Login $event): void
    {
        if ($event->guard === 'member') {
            $this->signer->rotate();
        }
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->guard === 'member') {
            $this->signer->forget();
        }
    }

    /** What `auth.session` fires, instead of Logout, on a device signed out by a password change elsewhere. */
    public function handleCurrentDeviceLogout(CurrentDeviceLogout $event): void
    {
        if ($event->guard === 'member') {
            $this->signer->forget();
        }
    }
}
