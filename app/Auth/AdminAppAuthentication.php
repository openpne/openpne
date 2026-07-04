<?php

namespace App\Auth;

use App\Models\AdminUser;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Facades\Filament;
use SensitiveParameter;

/**
 * The Filament TOTP provider, extended so that enabling or disabling MFA revokes the
 * administrator's other sessions — a change to the authenticating factor set, the same
 * class of event as a password change (App\Auth\SessionRevocation, established by the
 * account-revocation work). Regenerating recovery codes does not revoke: the TOTP
 * factor is unchanged, only the backup codes rotate.
 *
 * It also fixes the set-up QR code on servers without the imagick extension (common on
 * shared hosting): the provider's `getQRCodeInline` already returns a complete data: URI,
 * and Filament's parent re-base64-wraps it in that case, producing a double-encoded image
 * a browser cannot parse.
 */
class AdminAppAuthentication extends AppAuthentication
{
    public function generateQrCodeDataUri(#[SensitiveParameter] string $secret): string
    {
        $user = Filament::auth()->user();

        if (! $user instanceof HasAppAuthentication) {
            return parent::generateQrCodeDataUri($secret);
        }

        // Return the provider's data URI as-is (PNG with imagick, SVG otherwise) — no re-wrap.
        return $this->google2FA->getQRCodeInline(
            $this->getBrandName(),
            $this->getHolderName($user),
            $secret,
        );
    }

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
