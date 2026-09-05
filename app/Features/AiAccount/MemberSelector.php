<?php

declare(strict_types=1);

namespace App\Features\AiAccount;

use App\Models\Member;
use Illuminate\Support\Str;

/**
 * What the caller named the member by, carried so the act can ask it again under its lock; a key
 * needs no re-asking, so only an address is carried (docs/internals/mcp.md, "Running a bot member").
 */
final class MemberSelector
{
    private function __construct(
        private readonly Member $member,
        private readonly ?string $email,
    ) {}

    public static function of(Member $member): self
    {
        return new self($member, null);
    }

    public static function foundByEmail(Member $member, string $email): self
    {
        return new self($member, Str::lower(trim($email)));
    }

    /** Only the key is taken: every value judged is re-read under the lock. */
    public function member(): Member
    {
        return $this->member;
    }

    public function names(Member $locked): bool
    {
        return $this->email === null || Str::lower((string) $locked->email) === $this->email;
    }
}
