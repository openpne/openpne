<?php

namespace App\Auth\Reauth;

use App\Models\Member;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The one place a member request demands a step-up
 * (docs/internals/security.md, "Member re-authentication"). Whenever `current_password` is present
 * it is verified as the account password, whether or not this request had to send it.
 */
trait RequiresReauth
{
    private ?bool $reauthRequired = null;

    private ?string $reauthPath = null;

    abstract public function reauthPurpose(): ReauthPurpose;

    /**
     * Decided once per request, so a window that lapses mid-request cannot make the rules and the
     * verification disagree.
     */
    public function reauthRequired(): bool
    {
        return $this->reauthRequired ??= $this->demandsReauth();
    }

    /** The snapshot the verifier re-checks under the member row lock. */
    public function requiresSecondFactor(): bool
    {
        $viewer = $this->user();
        assert($viewer instanceof Member);

        return $this->asksSecondFactor() && $viewer->hasEnabledTwoFactorAuthentication();
    }

    public function reauthProof(): ReauthProof
    {
        if ($this->reauthPath() === 'none') {
            return ReauthProof::none();
        }

        if ($this->reauthPath() === 'passkey') {
            return ReauthProof::passkey($this->asksCodeBesidePasskey() && $this->requiresSecondFactor(), $this->input('code'));
        }

        return ReauthProof::password(
            (string) $this->input('current_password'),
            $this->asksSecondFactor() ? $this->requiresSecondFactor() : null,
            $this->input('code'),
            $this->allowsRecoveryCode() ? $this->input('recovery_code') : null,
        );
    }

    protected function demandsReauth(): bool
    {
        $window = $this->reauthPurpose()->window();

        return $window === null || ! $window::isFresh($this->session());
    }

    /** False only where the operation's own Action refuses a member who holds a confirmed factor. */
    protected function asksSecondFactor(): bool
    {
        return true;
    }

    protected function allowsRecoveryCode(): bool
    {
        return true;
    }

    /** True only where the operation's own demand is a TOTP code, which a passkey does not stand in for. */
    protected function asksCodeBesidePasskey(): bool
    {
        return false;
    }

    /** True when the Action verifies the proof inside the lock it mutates under. */
    protected function verifiesReauthInAction(): bool
    {
        return false;
    }

    /** @return array<string, mixed> */
    protected function reauthRules(): array
    {
        // requiredIf is implicit, so `nullable` does not defeat it and only lets the field pass when not demanded.
        return [
            'current_password' => [
                Rule::requiredIf(fn (): bool => $this->reauthPath() === 'password'),
                'current_password:member',
            ],
            'code' => [
                Rule::requiredIf(fn (): bool => $this->reauthPath() === 'password' && $this->requiresSecondFactor()
                    && ! ($this->allowsRecoveryCode() && $this->filled('recovery_code'))),
                'nullable',
                'string',
            ],
            'recovery_code' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // An empty field is "not provided", not a wrong password: the form submits it even when the
        // UI shows no field, and dropping it lets requiredIf decide.
        if (in_array($this->input('current_password'), [null, ''], true)) {
            $this->request->remove('current_password');
        }
    }

    protected function passedValidation(): void
    {
        if ($this->reauthPath() === 'none') {
            return;
        }

        $viewer = $this->user();
        assert($viewer instanceof Member);

        if ($this->reauthPath() === 'passkey' && ! PasskeyProof::consume($this->session(), $viewer, $this->reauthPurpose())) {
            throw ValidationException::withMessages(['current_password' => __('Please confirm it is you again.')]);
        }

        $proof = $this->reauthProof();

        if ($proof->comparesCode()) {
            app(ReauthCodeBudget::class)->reserve($viewer, $this->session());
        }

        if (! $this->verifiesReauthInAction()) {
            app(VerifyReauthProof::class)($viewer, $proof);
        }

        // The window runs from the proof that was asked for, not forward with each password sent inside it.
        $window = $this->reauthPurpose()->window();
        if ($window !== null && $this->reauthRequired()) {
            $window::stamp($this->session());
        }
    }

    /**
     * Decided once per request, like `reauthRequired()`. A password that was sent is always the path
     * taken, so a passkey proof never excuses a wrong one.
     */
    private function reauthPath(): string
    {
        if ($this->reauthPath !== null) {
            return $this->reauthPath;
        }

        $viewer = $this->user();
        assert($viewer instanceof Member);

        return $this->reauthPath = match (true) {
            $this->filled('current_password') => 'password',
            ! $this->reauthRequired() => 'none',
            PasskeyProof::stands($this->session(), $viewer, $this->reauthPurpose()) => 'passkey',
            default => 'password',
        };
    }
}
