<?php

namespace App\Features\Member\Actions;

use App\Models\Member;

/**
 * The explicit outcome of {@see ConsumeMfaReset}: the action owns every state transition, so the
 * controller branches on this instead of on a nullable Member (which conflated "dead link" with
 * "already off"). Exactly one of the three named constructors is returned:
 *
 *  - reset(Member)  the live factor was cleared; the member is exposed for the log/alert/session teardown.
 *  - alreadyOff()   the factor was no longer live; the spent link was burned, nothing to disable.
 *  - invalid()      the link was gone, replaced, expired, or the member withdrawn — a dead link.
 *
 * A wrong password is NOT an outcome here: {@see ConsumeMfaReset} throws ValidationException so the whole
 * transaction rolls back with the token intact.
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
