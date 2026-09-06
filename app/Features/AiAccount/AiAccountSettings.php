<?php

declare(strict_types=1);

namespace App\Features\AiAccount;

use App\Models\Member;
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

    /** Whether the member config offers its AI category: the offer is on, or the member already owns an account it must keep reaching. */
    public function availableTo(Member $member): bool
    {
        return $this->enabled() || $member->aiAccounts()->exists();
    }
}
