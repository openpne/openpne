<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\SecuritySettings;
use App\Models\AdminUser;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Nudges the signed-in administrator toward enabling two-factor authentication — the opt-in
 * posture relies on the prompt being visible, not on enforcement. Turns from a warning
 * call-to-action into a settled success stat once MFA is on. Links to SecuritySettings.
 */
class MfaReminderWidget extends StatsOverviewWidget
{
    protected ?string $heading = null;

    protected static ?int $sort = 4;

    protected function getStats(): array
    {
        return [
            $this->enabled()
                ? Stat::make(__('Two-factor authentication'), __('Enabled'))
                    ->description(__('Your administrator account is protected'))
                    ->color('success')
                    ->url(SecuritySettings::getUrl())
                : Stat::make(__('Two-factor authentication'), __('Not set up'))
                    ->description(__('Set it up to protect your account'))
                    ->color('warning')
                    ->url(SecuritySettings::getUrl()),
        ];
    }

    private function enabled(): bool
    {
        $admin = Filament::auth()->user();

        return $admin instanceof AdminUser
            && $admin instanceof HasAppAuthentication
            && filled($admin->getAppAuthenticationSecret());
    }
}
