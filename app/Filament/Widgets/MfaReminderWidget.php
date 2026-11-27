<?php

namespace App\Filament\Widgets;

use App\Models\Member;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * Rendered only while the signed-in administrator has no confirmed TOTP, even with a passkey: only
 * the one-time code stops a password reset from their mailbox opening the panel (docs/internals/security.md, "Administrators").
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

        return $admin instanceof Member && ! $admin->hasEnabledTwoFactorAuthentication();
    }

    // The member settings page, not its two-factor page: Classic keeps the two-factor and passkey
    // forms inline there.
    public function getSettingsUrl(): string
    {
        return route('member.config');
    }
}
