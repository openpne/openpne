<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The signed-in administrator's own two-factor authentication: enable (TOTP), disable, and
 * regenerate recovery codes. Filament's default host for this is the profile page, which the
 * panel does not register (an admin has no name/email); this dedicated page renders the same
 * management schema (mirroring EditProfile::getMultiFactorAuthenticationContentComponent) and
 * stays separate from the AdminUsers resource's password-change action.
 */
class SecuritySettings extends Page
{
    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedShieldCheck;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('Security');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Security');
    }

    public function content(Schema $schema): Schema
    {
        $user = Filament::auth()->user();

        return $schema->components([
            Section::make(__('Two-factor authentication'))
                ->description(__('Protect your administrator account with a one-time code from an authenticator app.'))
                ->compact()
                ->divided()
                ->schema(collect(Filament::getMultiFactorAuthenticationProviders())
                    ->sort(fn (MultiFactorAuthenticationProvider $provider): int => $provider->isEnabled($user) ? 0 : 1)
                    ->map(fn (MultiFactorAuthenticationProvider $provider): Group => Group::make($provider->getManagementSchemaComponents())
                        ->statePath($provider->getId()))
                    ->all()),
        ]);
    }
}
