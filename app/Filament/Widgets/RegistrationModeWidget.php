<?php

namespace App\Filament\Widgets;

use App\Features\Auth\RegistrationMode;
use App\Filament\Pages\RegistrationAuthSettings;
use App\Support\SnsSettingKey;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Shows the current registration mode so it isn't left closed (or wide open) unnoticed. Closed is
 * the only attention-grabbing state — registration silently suspended is the costly mistake.
 */
class RegistrationModeWidget extends StatsOverviewWidget
{
    protected ?string $heading = null;

    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        $mode = RegistrationMode::current();

        return [
            Stat::make(SnsSettingKey::RegistrationMode->label(), __($mode->label()))
                ->description(__('Who may create an account'))
                ->color($this->color($mode))
                ->url(RegistrationAuthSettings::getUrl()),
        ];
    }

    private function color(RegistrationMode $mode): string
    {
        return match ($mode) {
            RegistrationMode::Open => 'success',
            RegistrationMode::Invite => 'gray',
            RegistrationMode::AdminOnly => 'warning',
            RegistrationMode::Closed => 'danger',
        };
    }
}
