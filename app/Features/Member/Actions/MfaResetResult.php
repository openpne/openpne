<?php

namespace App\Features\Member\Actions;

use App\Models\Member;

/**
 * Three outcomes, so the controller does not branch on a nullable Member, which conflated a dead link
 * with an already-off factor. A wrong password is not among them: {@see ConsumeMfaReset} throws and
 * the token survives.
 */
final class MfaResetResult
{
    private const RESET = 'reset';

    private const ALREADY_OFF = 'already_off';

    private const INVALID = 'invalid';

    private function __construct(
        private readonly string $outcome,
        public readonly ?Member $member,
    ) {}

    public static function reset(Member $member): self
    {
        return new self(self::RESET, $member);
    }

    public static function alreadyOff(): self
    {
        return new self(self::ALREADY_OFF, null);
    }

    public static function invalid(): self
    {
        return new self(self::INVALID, null);
    }

    public function wasReset(): bool
    {
        return $this->outcome === self::RESET;
    }

    public function isAlreadyOff(): bool
    {
        return $this->outcome === self::ALREADY_OFF;
    }

    public function isInvalid(): bool
    {
        return $this->outcome === self::INVALID;
    }
}
