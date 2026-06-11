<?php

namespace App\Captcha;

use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;

/**
 * Resolves whether the bot challenge is on from the admin setting (`sns_settings`) at call time,
 * delegating to the real driver when on and to a no-op when off. Because the decision is read per
 * call rather than frozen when the singleton is built, toggling the setting takes effect on the next
 * request without rebuilding the container binding.
 */
class ConfigurableCaptcha implements Captcha
{
    public function __construct(
        private readonly SnsSettingService $settings,
        private readonly Captcha $driver,
        private readonly Captcha $disabled = new NullCaptcha,
    ) {}

    public function enabled(): bool
    {
        return $this->active()->enabled();
    }

    public function challenge(): array
    {
        return $this->active()->challenge();
    }

    public function verify(?string $payload): bool
    {
        return $this->active()->verify($payload);
    }

    private function active(): Captcha
    {
        return $this->settings->get(SnsSettingKey::CaptchaEnabled) ? $this->driver : $this->disabled;
    }
}
