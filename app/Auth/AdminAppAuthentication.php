<?php

namespace App\Auth;

use App\Models\AdminUser;
use App\Support\SecurityLog;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Auth\Authenticatable;
use LogicException;
use SensitiveParameter;

/**
 * Enabling or disabling MFA revokes the administrator's other sessions, as a password change does;
 * regenerating recovery codes leaves the factor unchanged and revokes nothing. The password on the
 * three management actions is Filament's own field, gated by OpenPNE's throttle policy in the hook
 * slots (docs/internals/security.md, "Admin two-factor authentication").
 */
class AdminAppAuthentication extends AppAuthentication
{
    private const MANAGEMENT_ACTIONS = [
        'setUpAppAuthentication',
        'disableAppAuthentication',
        'regenerateAppAuthenticationRecoveryCodes',
    ];

    public function generateQrCodeDataUri(#[SensitiveParameter] string $secret): string
    {
        $user = Filament::auth()->user();

        if (! $user instanceof HasAppAuthentication) {
            return parent::generateQrCodeDataUri($secret);
        }

        // Without imagick getQRCodeInline already returns a complete data: URI, which the parent
        // would base64-wrap a second time.
        return $this->google2FA->getQRCodeInline(
            $this->getBrandName(),
            $this->getHolderName($user),
            $secret,
        );
    }

    /**
     * The parent consumes the code, so a re-validation of the same code returns false and this logs
     * at most once per code, never the code itself. Admin TOTP-code failure is deliberately not
     * logged (docs/internals/logging.md).
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
     * Browsers honour autofocus only while the document loads and the challenge is Livewire-morphed
     * in after that, so the code input also focuses itself on insertion, deferred a tick to outlast
     * the morph's own focus handling.
     *
     * @param  Authenticatable&HasAppAuthentication&HasAppAuthenticationRecovery  $user
     * @return array<Component>
     */
    public function getChallengeFormComponents(Authenticatable $user): array
    {
        return array_map(
            fn (Component $component): Component => $component instanceof OneTimeCodeInput
                ? $component->autofocus()->extraInputAttributes(['x-init' => '$nextTick(() => $el.focus())'])
                : $component,
            parent::getChallengeFormComponents($user),
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

            $this->requirePassword($action);

            // No framework event fires for these admin-side changes, so the after-hook logs them and,
            // for a factor change, revokes the other sessions.
            $event = match ($action->getName()) {
                'setUpAppAuthentication' => 'mfa.enabled',
                'disableAppAuthentication' => 'mfa.disabled',
                'regenerateAppAuthenticationRecoveryCodes' => 'mfa.recovery_codes_regenerated',
                default => null,
            };

            if ($event !== null) {
                // Filament runs after() even when the action body saves nothing (set-up args minted
                // for another admin are discarded), so the hooks compare the raw persisted columns
                // around the body and skip the log and the revocation unless something changed.
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
     * Action::$schema has no getter, so the vendor schema is read through a bound closure. A Filament
     * rename of that property throws here at runtime, which is preferred to the password gate
     * silently disappearing.
     */
    private function requirePassword(Action $action): void
    {
        if (! in_array($action->getName(), self::MANAGEMENT_ACTIONS, true)) {
            return;
        }

        // A single slot: this replaces the vendor closure (its hit-on-every-attempt limiter and its
        // validateOnly) rather than running ahead of it.
        $action->beforeFormValidated(
            fn (HasActions&HasSchemas $livewire) => AdminMfaPasswordReauth::gate(self::mountedPasswordField($livewire)),
        );

        $readSchema = Closure::bind(fn (Action $a) => $a->schema, null, Action::class);
        $vendorRaw = $readSchema($action);

        match ($action->getName()) {
            // The captured raw value is re-evaluated inside a fresh closure so nothing accumulates
            // across renders.
            'setUpAppAuthentication' => $action->steps(
                fn (Action $action): array => self::gateAppStep($action->evaluate($vendorRaw)),
            ),
            'regenerateAppAuthenticationRecoveryCodes' => self::requireCode($vendorRaw),
            default => null,
        };
    }

    /**
     * The step's own slot holds the vendor limiter and is replaced the same way as beforeFormValidated.
     *
     * @param  array<Step>  $steps
     * @return array<Step>
     */
    private static function gateAppStep(array $steps): array
    {
        foreach ($steps as $step) {
            if ($step instanceof Step && $step->getLabel() === 'app') {
                $step->beforeValidation(
                    fn (Step $step) => AdminMfaPasswordReauth::gate(self::passwordField($step->getChildSchema())),
                );

                return $steps;
            }
        }

        throw new LogicException('Expected the vendor set-up wizard to carry an "app" step.');
    }

    private static function mountedPasswordField(HasActions&HasSchemas $livewire): Field
    {
        $name = $livewire->getMountedActionSchemaName();
        $schema = $name === null ? null : $livewire->getSchema($name);

        if ($schema === null) {
            throw new LogicException('Expected a mounted MFA action schema.');
        }

        return self::passwordField($schema);
    }

    private static function passwordField(Schema $schema): Field
    {
        $field = $schema->getComponent('password');

        if (! $field instanceof Field) {
            throw new LogicException('Expected the vendor MFA schema to carry a password field.');
        }

        return $field;
    }

    /**
     * The vendor code field is only requiredWithout('password'), so without this the password alone
     * would regenerate. Same loud-break stance as the schema read above: a vendor rename throws rather
     * than silently reopening that path.
     *
     * @param  array<mixed>|Closure|null  $vendorRaw
     */
    private static function requireCode(array|Closure|null $vendorRaw): void
    {
        if (! is_array($vendorRaw)) {
            throw new LogicException('Expected the vendor regenerate schema to be a component array.');
        }

        foreach ($vendorRaw as $component) {
            if ($component instanceof Field && $component->getName() === 'code') {
                $component->required();

                return;
            }
        }

        throw new LogicException('Expected the vendor regenerate schema to carry a code field.');
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
