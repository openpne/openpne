<?php

namespace App\Actions\Fortify;

use App\Auth\PasswordScheme;
use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Fortify;

/**
 * Validates member login credentials, upgrading a wrapped OpenPNE 3 password to a
 * plain bcrypt on the way through.
 *
 * The upgrade stores an imported password as bcrypt over its OpenPNE 3 MD5 hex and
 * flags the row (PasswordScheme::Md5Bcrypt) — bare MD5 never sits at rest. This
 * callback (wired via Fortify::authenticateUsing) verifies a flagged row by md5()ing
 * the attempt first, then rehashes to a plain bcrypt in place; the wrapper — and the
 * flag — are gone after the member's first successful login.
 */
class AuthenticateMember
{
    public function __invoke(Request $request): ?Member
    {
        // CanonicalizeUsername has already lowercased the username earlier in the pipeline.
        $member = Member::query()
            ->where('email', $request->input(Fortify::username()))
            ->first();

        $password = (string) $request->input('password');

        // The third refusal for an AI account, behind the two that make the row unreachable already
        // (no email to match, no password to verify) and the members CHECK that keeps it that way.
        // Stated once here so the rule is "an AI account never logs in", not an emergent property of
        // two null columns — and so a row that somehow carries credentials still cannot.
        if (! $member || $member->password === null || $member->isAiAccount()) {
            return $this->rejectInConstantTime();
        }

        // The scheme decides first: a wrapped hash IS a bcrypt string, so isHashed cannot
        // tell it apart. An unrecognised stored form (a bare MD5 the wrap pass has not
        // converted) authenticates nobody — verify-upgrade holds the cutover to zero such rows.
        $verified = match (true) {
            $member->password_scheme === PasswordScheme::Md5Bcrypt->value => $this->verifyWrapped($member, $password),
            Hash::isHashed($member->password) => $this->verifyCurrent($member, $password),
            default => $this->rejectInConstantTime(),
        };

        // An admin-rejected (OpenPNE 3 is_login_rejected) member cannot log in even with the right
        // password. Checked after verification so the ban is invisible to anyone without the
        // credentials — a wrong password fails the same way whether or not the account is banned.
        if ($verified?->is_login_rejected) {
            return null;
        }

        return $verified;
    }

    private function verifyCurrent(Member $member, string $password): ?Member
    {
        if (! Hash::check($password, $member->password)) {
            return null;
        }

        return Hash::needsRehash($member->password)
            ? $this->store($member, $password)
            : $member;
    }

    /**
     * A rejection that skipped hash verification — an unknown email, a passwordless row,
     * an unrecognised stored form — must not answer faster than one that ran it: the
     * difference (a bcrypt takes hundreds of milliseconds) is an account-existence
     * oracle. Burn an equivalent hash; Hash::make costs the same as the check a real
     * account gets and tracks the configured rounds. A fixed input, not the attempt:
     * bcrypt cost is input-independent, and a configured BCRYPT_LIMIT would otherwise
     * let an over-long attempt throw on exactly this path.
     */
    private function rejectInConstantTime(): ?Member
    {
        Hash::make('openpne-timing-equalizer');

        return null;
    }

    /** The stored hash is bcrypt over the OpenPNE 3 MD5 hex, so md5() the attempt first. */
    private function verifyWrapped(Member $member, string $password): ?Member
    {
        if (! Hash::check(md5($password), $member->password)) {
            return null;
        }

        return $this->store($member, $password);
    }

    /**
     * Persist a freshly hashed password. Hash explicitly rather than leaning on the model's
     * `hashed` cast: the cast leaves an already-hash-shaped string untouched, so passing the
     * raw plaintext could skip hashing for a password that happens to look like a hash. The
     * save also clears password_scheme (ClearsPasswordScheme), retiring a wrapped row's md5
     * pre-step.
     */
    private function store(Member $member, string $password): Member
    {
        $member->forceFill(['password' => Hash::make($password)])->save();

        return $member;
    }
}
