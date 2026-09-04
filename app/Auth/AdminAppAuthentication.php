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
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Contracts\Auth\Authenticatable;
use LogicException;
use SensitiveParameter;

/**
 * Enabling or disabling MFA revokes the administrator's other sessions, as a password change does;
 * regenerating recovery codes leaves the factor unchanged and revokes nothing. Every management
 * action also demands the account password inline (AdminMfaPasswordReauth, docs/internals/security.md).
 */
class AdminAppAuthentication extends AppAuthentication
{
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
                // for another admin are discarded), so the hooks compare the persisted columns around
                // the body and skip both unless something changed.
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
        $readSchema = Closure::bind(fn (Action $a) => $a->schema, null, Action::class);
        $vendorRaw = $readSchema($action);

        match ($action->getName()) {
            // The vendor steps are never introspected, since an unattached Step throws on child access,
            // and the captured raw value is re-evaluated inside a fresh closure so nothing accumulates
            // across renders.
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
            // The captured vendor field instances are mutated in place once per render, so ->rule()
            // cannot accumulate.
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
        // markAsRequired, not required(): Laravel stops an attribute's rules once `required` fails, so a
        // blank password would skip the fail-fast rule and let the recovery-code field consume a code.
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
     * Same loud-break stance as the schema read above: if a Filament upgrade turns this schema
     * into a closure or renames the fields, throw rather than silently dropping the password gate.
     *
     * @param  array<mixed>|Closure|null  $vendorRaw
     */
    private static function requirePasswordAndCode(array|Closure|null $vendorRaw): void
    {
        if (! is_array($vendorRaw)) {
            throw new LogicException('Expected the vendor regenerate schema to be a component array.');
        }

        $required = [];

        foreach ($vendorRaw as $component) {
            if (! $component instanceof Field) {
                continue;
            }

            match ($component->getName()) {
                'code' => $required[] = $component->required(),
                // The vendor label's or-phrasing ("Or, enter your current password") is misleading once
                // both fields are required.
                'password' => $required[] = $component->required()
                    ->label(__('Current password'))
                    ->rule(new AdminMfaPasswordReauth),
                default => null,
            };
        }

        if (count($required) !== 2) {
            throw new LogicException('Expected the vendor regenerate schema to carry both a code and a password field.');
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
