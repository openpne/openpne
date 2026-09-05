<?php

declare(strict_types=1);

namespace App\Features\AiAccount;

use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;

/**
 * Both are creation-time questions only: nothing here gates managing, deleting or revoking tokens
 * for an account that already exists.
 */
final class AiAccountSettings
{
    public function __construct(private readonly SnsSettingService $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get(SnsSettingKey::AiAccountsEnabled);
    }

    public function limit(): int
    {
        return (int) $this->settings->get(SnsSettingKey::AiAccountLimit);
    }
}
