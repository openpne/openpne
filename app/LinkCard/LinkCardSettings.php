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
    private ?bool $enabled = null;

    public function __construct(private readonly SnsSettingService $settings) {}

    /**
     * Remembered for as long as this instance lives, which the container makes one request or one
     * queued job (AppServiceProvider binds it scoped). The setting is stored behind a cache the
     * default store keeps in the database, so a fresh read costs a query — and a page of talk asks
     * once per message, twice over.
     */
    public function enabled(): bool
    {
        return $this->enabled ??= (bool) $this->settings->get(SnsSettingKey::LinkCardEnabled);
    }
}
