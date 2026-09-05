<?php

declare(strict_types=1);

namespace App\Features\GroupTalk;

use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;

/**
 * The memo lives as long as the container's scoped binding (AppServiceProvider), so it ends with the
 * request or the job rather than with the worker process.
 */
final class GroupTalkNotifyDefault
{
    private ?GroupTalkNotifyMode $mode = null;

    public function __construct(private readonly SnsSettingService $settings) {}

    public function mode(): GroupTalkNotifyMode
    {
        return $this->mode ??= GroupTalkNotifyMode::tryFrom((string) $this->settings->get(SnsSettingKey::GroupTalkNotifyDefault))
            ?? GroupTalkNotifyMode::Mentions;
    }
}
