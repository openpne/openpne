<?php

namespace App\Auth;

use App\Models\AdminUser;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;

/**
 * The Filament TOTP provider, extended so that enabling or disabling MFA revokes the
 * administrator's other sessions — a change to the authenticating factor set, the same
 * class of event as a password change (App\Auth\SessionRevocation, established by the
 * account-revocation work). Regenerating recovery codes does not revoke: the TOTP
 * factor is unchanged, only the backup codes rotate.
 */
class AdminAppAuthentication extends AppAuthentication
{
    /**
     * @return array<Action>
     */
    public function getActions(): array
    {
        return array_map(function (Action $action): Action {
            // Render as an actual button, not Filament's default link, so the set-up /
            // disable controls read as clickable.
            $action->button();

            if (in_array($action->getName(), ['setUpAppAuthentication', 'disableAppAuthentication'], true)) {
                $action->after(function (): void {
                    $admin = Filament::auth()->user();
                    if ($admin instanceof AdminUser) {
                        // Keep the session that just made the change; drop every other device.
                        SessionRevocation::revokeAdmin($admin, session()->getId());
                    }
                });
            }

            return $action;
        }, parent::getActions());
    }
}
