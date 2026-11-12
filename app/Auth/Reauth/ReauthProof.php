<?php

namespace App\Auth\Reauth;

use SensitiveParameter;

/**
 * What a request offered, not yet a verdict: `secondFactorDemanded` is the request-time snapshot of
 * whether a confirmed factor existed, which the verifier re-checks under the member row lock. Null
 * means the step-up never asks for one, so the verifier compares nothing.
 */
final readonly class ReauthProof
{
    private function __construct(
        public bool $offered,
        public bool $viaPasskey,
        #[SensitiveParameter] public string $password,
        public ?bool $secondFactorDemanded,
        public ?string $code,
        public ?string $recoveryCode,
    ) {}

    /** A request that was not asked to prove anything (a fresh window, an inert target). */
    public static function none(): self
    {
        return new self(false, false, '', null, null, null);
    }

    public static function password(#[SensitiveParameter] string $password, ?bool $secondFactorDemanded, ?string $code, ?string $recoveryCode): self
    {
        return new self(true, false, $password, $secondFactorDemanded, $code, $recoveryCode);
    }

    /**
     * Verified only once the request has consumed its passkey proof. It stands for the second factor too,
     * so `$codeDemanded` is null unless the operation itself asks for a TOTP code.
     */
    public static function passkey(?bool $codeDemanded, ?string $code): self
    {
        return new self(true, true, '', $codeDemanded, $code, null);
    }

    public function comparesCode(): bool
    {
        return $this->secondFactorDemanded === true && (string) $this->recoveryCode === '';
    }
}
