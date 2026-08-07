<?php

declare(strict_types=1);

namespace App\LinkCard;

use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;

/**
 * Whether this site shows link preview cards.
 *
 * One question, asked from three places, and all three have to agree or the switch does not mean
 * what it says: the read path (do not start work), the fetch job (do not make the request, even if
 * it was queued while the setting was on), and the renderer (do not show a card fetched earlier).
 * Reading the setting through one method rather than three call sites is what keeps a later change
 * from being applied to only two of them.
 */
final class LinkCardSettings
{
    public function __construct(private readonly SnsSettingService $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get(SnsSettingKey::LinkCardEnabled);
    }
}
