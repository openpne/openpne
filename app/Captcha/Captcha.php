<?php

namespace App\Captcha;

/**
 * A bot challenge gate. The default driver is self-hosted ALTCHA proof-of-work; NullCaptcha stands
 * in when CAPTCHA is disabled (or in tests) so callers never branch on whether it is on.
 */
interface Captcha
{
    /** Whether a challenge is actually enforced (false for NullCaptcha) — drives whether the UI renders the widget. */
    public function enabled(): bool;

    /**
     * The challenge the widget fetches and solves, or [] when disabled.
     *
     * @return array<string, mixed>
     */
    public function challenge(): array;

    /** Verify the widget's submitted solution payload (base64 JSON), false on anything malformed. */
    public function verify(?string $payload): bool;
}
