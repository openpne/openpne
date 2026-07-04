<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\SecuritySettings;
use App\Models\AdminUser;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * A full-width call-to-action prompting the signed-in administrator to enable two-factor
 * authentication — the opt-in posture relies on the prompt being seen. Rendered only while
 * MFA is off (a nudge you've acted on should not linger; the Security nav item remains the
 * ongoing entry point), and styled as an alert, not a stat, so it reads as being about the
 * operator's own account rather than a member metric.
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
