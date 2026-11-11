<?php

namespace App\Auth\Reauth;

use App\Models\Member;
use Illuminate\Validation\Rule;

/**
 * The one place a member request demands a step-up
 * (docs/internals/security.md, "Member re-authentication"). Whenever `current_password` is present
 * it is verified as the account password, whether or not this request had to send it.
 */
trait RequiresReauth
{
    private ?bool $reauthRequired = null;

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
        if (! $this->reauthRequired() && ! $this->filled('current_password')) {
            return ReauthProof::none();
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
                Rule::requiredIf(fn (): bool => $this->reauthRequired()),
                'current_password:member',
            ],
            'code' => [
                Rule::requiredIf(fn (): bool => $this->offersPassword() && $this->requiresSecondFactor()
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
        if (! $this->offersPassword()) {
            return;
        }

        if (! $this->verifiesReauthInAction()) {
            $viewer = $this->user();
            assert($viewer instanceof Member);
            app(VerifyReauthProof::class)($viewer, $this->reauthProof());
        }

        $window = $this->reauthPurpose()->window();
        if ($window !== null) {
            $window::stamp($this->session());
        }
    }

    private function offersPassword(): bool
    {
        return $this->reauthRequired() || $this->filled('current_password');
    }
}
