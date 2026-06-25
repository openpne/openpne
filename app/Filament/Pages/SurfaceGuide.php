<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Read-only orientation for the Classic / Modern surfaces. Explains what each is and shows, from config,
 * which surface the site serves by default and whether members may switch — so the "Classic-only" note
 * on the other appearance screens has somewhere to point. No form: config/env is authoritative
 * (`openpne.tenant_mode` / `openpne.tenant_default_surface`, read by App\Support\SurfaceResolver).
 */
class SurfaceGuide extends Page
{
    protected string $view = 'filament.pages.surface-guide';

    protected static ?int $navigationSort = 0;

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedComputerDesktop;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Appearance');
    }

    public static function getNavigationLabel(): string
    {
        return __('Display mode');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Display mode (Classic / Modern)');
    }

    /**
     * The site's default surface, derived from config exactly as SurfaceResolver does at the tenant level
     * (per-feature Classic pins and per-member choices sit on top, described in the copy, not computed here).
     *
     * @return array{modernOnly: bool, default: string}
     */
    public function surface(): array
    {
        $modernOnly = config('openpne.tenant_mode') === 'modern_only';

        return [
            'modernOnly' => $modernOnly,
            'default' => config('openpne.tenant_default_surface') === 'modern' ? 'modern' : 'classic',
        ];
    }
}
