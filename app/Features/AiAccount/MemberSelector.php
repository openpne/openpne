<?php

declare(strict_types=1);

namespace App\Features\AiAccount;

use App\Models\Member;
use Illuminate\Support\Str;

/**
 * Which member a token act is for, and what the caller named them by.
 *
 * The mint and the revoke re-read every value they judge from the row they lock. What they cannot
 * re-read is the caller's question: a caller that looks a member up by address, hands the row over
 * and lets the act run from that snapshot leaves a window in which the address is changed or handed
 * to someone else, and the act then lands on an id that no longer answers to what was asked for.
 * Carrying the question rather than only its answer lets the act put it again under the lock.
 *
 * A key needs no re-asking — it names one row for good — so only the address is carried. An act
 * whose address has moved is refused rather than retargeted: the caller has already named that
 * member in what it reported, and mutating a different one behind that report would be worse than
 * asking again.
 */
final class MemberSelector
{
    private function __construct(
        private readonly Member $member,
        private readonly ?string $email,
    ) {}

    /** The member the caller holds, named by key alone. */
    public static function of(Member $member): self
    {
        return new self($member, null);
    }

    /** The member the caller found by address, which the act re-checks against the locked row. */
    public static function foundByEmail(Member $member, string $email): self
    {
        return new self($member, Str::lower(trim($email)));
    }

    /** Whose row to lock. Only its key is taken — every value judged is re-read under that lock. */
    public function member(): Member
    {
        return $this->member;
    }

    /** Whether the row read back under the lock is still the one that was asked for. */
    public function names(Member $locked): bool
    {
        return $this->email === null || Str::lower((string) $locked->email) === $this->email;
    }
}
