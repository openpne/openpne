<?php

declare(strict_types=1);

namespace App\Files;

use App\Services\SnsSettingService;
use App\Support\FaviconFallback;
use App\Support\SnsSettingKey;

/** Which icon the site shows, and where each shell finds it (docs/internals/site-icon.md). */
class SiteIcon
{
    public function __construct(
        private readonly SnsSettingService $settings,
        private readonly GeneratedIcon $generated,
    ) {}

    public function state(): SiteIconState
    {
        if ((string) $this->settings->get(SnsSettingKey::BrandFaviconFile) !== '') {
            return SiteIconState::Uploaded;
        }

        return $this->settings->get(SnsSettingKey::BrandFaviconFallback) === FaviconFallback::None
            ? SiteIconState::None
            : SiteIconState::Generated;
    }

    /** The single tab icon URL for a shell that links one, or null when the site shows none. */
    public function faviconUrl(): ?string
    {
        return match ($this->state()) {
            SiteIconState::Uploaded => brand_favicon_url(),
            SiteIconState::Generated => route('favicon.svg', ['v' => $this->generated->version()]),
            SiteIconState::None => null,
        };
    }

    /** The home-screen icon at $size: derived from the upload when there is one, else the brand tile. */
    public function appIconUrl(int $size): string
    {
        $token = (string) $this->settings->get(SnsSettingKey::BrandFaviconFile);

        return $token === ''
            ? route('site_icon', ['size' => $size, 'v' => $this->generated->version()])
            : route('app_icon', ['token' => $token, 'size' => $size]);
    }

    public function version(): string
    {
        return $this->generated->version();
    }
}
