<?php

namespace App\Auth;

use App\Models\AdminUser;
use App\Support\SecurityLog;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
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
     * A recovery code was spent — at the login challenge or to authorize a disable. The parent
     * consumes the code (idempotent: a re-validation of the same code returns false), so this logs
     * at most once per code, and never the code itself. Admin TOTP-code *failure* is deliberately
     * not logged (see docs/internals/logging.md).
     */
    public function verifyRecoveryCode(#[SensitiveParameter] string $recoveryCode, ?HasAppAuthenticationRecovery $user = null): bool
    {
        $verified = parent::verifyRecoveryCode($recoveryCode, $user);

        if ($verified) {
            // At the login challenge the admin is not yet authenticated, so the challenged $user is
            // passed; the disable/regenerate flows pass null and the acting admin is authenticated.
            $subject = $user ?? Filament::auth()->user();
            SecurityLog::event('mfa.recovery_code_used', [
                'guard' => 'admin',
                'username' => $subject instanceof AdminUser ? $subject->username : null,
            ]);
        }

        return $verified;
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

            // No framework event fires for these admin-side changes; log (and, for a factor
            // change, revoke other sessions) in the action's after-hook. Regenerating recovery
            // codes leaves the factor unchanged, so it logs without revoking (member parity).
            $event = match ($action->getName()) {
                'setUpAppAuthentication' => 'mfa.enabled',
                'disableAppAuthentication' => 'mfa.disabled',
                'regenerateAppAuthenticationRecoveryCodes' => 'mfa.recovery_codes_regenerated',
                default => null,
            };

            if ($event !== null) {
                // Filament runs after() even when the action body no-ops (e.g. set-up args minted
                // for a different admin are discarded without saving), so the hooks compare the
                // persisted columns around the body and skip both the log and the revocation
                // unless a real change happened. Raw column reads: no need to decrypt to compare.
                $before = (object) ['secret' => null, 'codes' => null];

                $action->before(function () use ($before): void {
                    [$before->secret, $before->codes] = self::persistedFactorState();
                });

                $action->after(function () use ($event, $before): void {
                    $admin = Filament::auth()->user();
                    if (! $admin instanceof AdminUser) {
                        return;
                    }

                    [$secret, $codes] = self::persistedFactorState();
                    $changed = match ($event) {
                        'mfa.enabled' => blank($before->secret) && filled($secret),
                        'mfa.disabled' => filled($before->secret) && blank($secret),
                        default => $codes !== $before->codes,
                    };
                    if (! $changed) {
                        return;
                    }

                    if ($event !== 'mfa.recovery_codes_regenerated') {
                        // Keep the session that just made the change; drop every other device.
                        SessionRevocation::revokeAdmin($admin, session()->getId());
                    }

                    SecurityLog::event($event, ['guard' => 'admin', 'username' => $admin->username]);
                });
            }

            return $action;
        }, parent::getActions());
    }

    /**
     * The acting admin's MFA columns as persisted right now — a fresh query, not the cached auth
     * instance, whose attributes may not reflect what the action body saved (or declined to save).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function persistedFactorState(): array
    {
        $fresh = AdminUser::query()->find(Filament::auth()->id());

        return [
            $fresh?->getRawOriginal('app_authentication_secret'),
            $fresh?->getRawOriginal('app_authentication_recovery_codes'),
        ];
    }
}
