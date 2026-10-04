<?php

declare(strict_types=1);

namespace App\LinkCard;

use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;

/**
 * One question asked from four places, and all four have to agree or the switch does not mean what
 * it says: the read path (refetch no due card), the sync job (queue no fetch), the fetch job (make no
 * request, even if already queued), and the renderer (show no card fetched earlier). Read through one
 * method so a later change cannot reach only some of them.
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
