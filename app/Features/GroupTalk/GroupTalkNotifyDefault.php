<?php

declare(strict_types=1);

namespace App\Features\GroupTalk;

use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;

/**
 * The site's talk notification default (App\Features\GroupTalk\GroupTalkNotifyMode).
 *
 * Asked far more often than an admin setting usually is: it is the web default of a catalog kind,
 * so every `via()`, every settings-form serialization and every fan-out decision reads it. The
 * setting sits behind a cache the default store keeps in the database, so a fresh read costs a
 * query — hence the memo, and the scoped binding (AppServiceProvider) that ends it with the request
 * or the job rather than with the worker process.
 */
final class GroupTalkNotifyDefault
{
    private ?GroupTalkNotifyMode $mode = null;

    public function __construct(private readonly SnsSettingService $settings) {}

    /** An unreadable value is corruption rather than a decision, and lands on the quieter mode. */
    public function mode(): GroupTalkNotifyMode
    {
        return $this->mode ??= GroupTalkNotifyMode::tryFrom((string) $this->settings->get(SnsSettingKey::GroupTalkNotifyDefault))
            ?? GroupTalkNotifyMode::Mentions;
    }
}
