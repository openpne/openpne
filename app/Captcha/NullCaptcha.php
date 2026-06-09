<?php

namespace App\Captcha;

/** The no-op captcha used when the feature is disabled: no challenge, and every solution passes. */
class NullCaptcha implements Captcha
{
    public function enabled(): bool
    {
        return false;
    }

    public function challenge(): array
    {
        return [];
    }

    public function verify(?string $payload): bool
    {
        return true;
    }
}
