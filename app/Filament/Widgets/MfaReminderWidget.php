<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\SecuritySettings;
use App\Models\AdminUser;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * Rendered only while the signed-in administrator's MFA is off: the opt-in posture relies on the
 * prompt being seen, and an acted-on nudge should not linger.
 */
class MfaReminderWidget extends Widget
{
    protected string $view = 'filament.widgets.mfa-reminder';

    protected int|string|array $columnSpan = 'full';

    // Above the member-stat widgets (sort 1+), so the account prompt is the first thing seen.
    protected static ?int $sort = -1;

    public static function canView(): bool
    {
        $admin = Filament::auth()->user();

        return $admin instanceof AdminUser
            && $admin instanceof HasAppAuthentication
            && blank($admin->getAppAuthenticationSecret());
    }

    public function getSecurityUrl(): string
    {
        return SecuritySettings::getUrl();
    }
}
