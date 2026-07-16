<?php

namespace App\Auth;

use App\Models\AdminUser;
use App\Support\SecurityLog;
use Closure;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Wizard\Step;
use SensitiveParameter;

/**
 * The Filament TOTP provider, extended so that enabling or disabling MFA revokes the
 * administrator's other sessions — a change to the authenticating factor set, the same
 * class of event as a password change (App\Auth\SessionRevocation, established by the
 * account-revocation work). Regenerating recovery codes does not revoke: the TOTP
 * factor is unchanged, only the backup codes rotate.
 *
 * All three management actions additionally require the administrator's account password inline
 * ("sudo mode", App\Auth\AdminMfaPasswordReauth): set-up prepends an identity step, disable puts the
 * password first, and regenerate is tightened from password-OR-code to password-AND-code. The pre-
 * existing code requirements are kept. This blocks a walked-up unlocked session from enrolling or
 * rotating a factor and closes the password-only regenerate path that could mint TOTP-bypassing
 * recovery codes. See docs/internals/security.md.
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

            $this->requirePassword($action);

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
     * Fold the inline password re-auth into each management action.
     *
     * The raw value behind schema()/steps() lives in the protected Action::$schema property, which
     * has no getter (schema()/steps() only replace it). Reading it through a bound closure and re-
     * wrapping is a deliberate loud-break dependency: if a Filament upgrade renames the property this
     * throws at runtime and AdminMfaReauthTest goes red rather than the guard silently disappearing.
     */
    private function requirePassword(Action $action): void
    {
        $readSchema = Closure::bind(fn (Action $a) => $a->schema, null, Action::class);
        $vendorRaw = $readSchema($action);

        match ($action->getName()) {
            // Wizard: prepend a self-contained identity step. The vendor steps are never introspected
            // (an unattached Step throws on child access) — the captured raw value is only re-evaluated
            // inside a fresh wrapper, and a new array is built per render so nothing accumulates. As a
            // side effect the password is now asked before the QR code is shown.
            'setUpAppAuthentication' => $action->steps(fn (Action $action): array => [
                self::identityStep(),
                ...$action->evaluate($vendorRaw),
            ]),
            // Password field first: validation runs in component order and its fail-fast rule must throw
            // before the vendor recovery-code rule (which consumes the code) is ever reached.
            'disableAppAuthentication' => $action->schema(fn (Action $action): array => [
                self::currentPasswordField(),
                ...$action->evaluate($vendorRaw),
            ]),
            // The vendor schema already carries the password and code fields; make both mandatory (was
            // password-OR-code) and route the password through the shared throttled rule. The captured
            // field instances are mutated in place — once per render — so ->rule() cannot accumulate.
            'regenerateAppAuthenticationRecoveryCodes' => self::requirePasswordAndCode($vendorRaw),
            default => null,
        };
    }

    /** The shared re-auth field. See currentPasswordField()/AdminMfaPasswordReauth for why markAsRequired. */
    private static function identityStep(): Step
    {
        return Step::make('identity')->schema([
            Text::make(__('To continue, first confirm it is you.')),
            self::currentPasswordField(),
        ]);
    }

    private static function currentPasswordField(): TextInput
    {
        // markAsRequired, not required(): a real `required` rule is implicit and Laravel short-circuits
        // the attribute once it fails, so on a blank password the fail-fast AdminMfaPasswordReauth would
        // never run and the disable modal's recovery-code field could still be validated and consume a
        // code. markAsRequired shows the asterisk; the implicit rule enforces blank/wrong/throttled.
        return TextInput::make('current_password')
            ->label(__('Current password'))
            ->password()
            ->revealable(Filament::arePasswordsRevealable())
            ->autocomplete('current-password')
            ->markAsRequired()
            ->rule(new AdminMfaPasswordReauth)
            ->dehydrated(false); // keep the password out of getData(), the hooks and the logs
    }

    /**
     * @param  array<mixed>|Closure|null  $vendorRaw
     */
    private static function requirePasswordAndCode(array|Closure|null $vendorRaw): void
    {
        if (! is_array($vendorRaw)) {
            return;
        }

        foreach ($vendorRaw as $component) {
            if (! $component instanceof Field) {
                continue;
            }

            match ($component->getName()) {
                'code' => $component->required(),
                'password' => $component->required()->rule(new AdminMfaPasswordReauth),
                default => null,
            };
        }
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
