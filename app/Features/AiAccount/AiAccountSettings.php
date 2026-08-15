<?php

declare(strict_types=1);

namespace App\Features\AiAccount;

use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;

/**
 * The site's answer to "may a member create an AI account, and how many?".
 *
 * Both are creation-time questions only. Nothing here gates managing, deleting or revoking tokens
 * for an account that already exists: an operator switching the feature off is closing the door, not
 * confiscating what is behind it — the remediation paths have to keep working either way.
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
