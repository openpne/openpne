<?php

namespace App\Actions\Fortify;

use App\Auth\PasswordScheme;
use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Fortify;

/**
 * A row flagged PasswordScheme::Md5Bcrypt is rehashed to a plain bcrypt on the member's
 * first successful login, so the wrapper and its flag never outlive that login.
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

        // The explicit AI-account refusal is deliberate: a row that somehow carries an email and a
        // password must still never log in.
        if (! $member || $member->password === null || $member->isAiAccount()) {
            return $this->rejectInConstantTime();
        }

        // The scheme is checked before isHashed because a wrapped hash is itself a bcrypt string,
        // and an unrecognised stored form (an unconverted bare MD5) authenticates nobody.
        $verified = match (true) {
            $member->password_scheme === PasswordScheme::Md5Bcrypt->value => $this->verifyWrapped($member, $password),
            Hash::isHashed($member->password) => $this->verifyCurrent($member, $password),
            default => $this->rejectInConstantTime(),
        };

        // Checked after verification so a ban is invisible without the credentials: a wrong
        // password fails the same way whether or not the account is banned.
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
     * A rejection that skipped hash verification burns an equivalent bcrypt, so response time is
     * not an account-existence oracle. A fixed input rather than the attempt: a configured
     * BCRYPT_LIMIT would let an over-long attempt throw on exactly this path.
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
     * Hashed explicitly rather than through the model's `hashed` cast, which would store a
     * plaintext that happens to look like a hash untouched. The save also clears password_scheme
     * (ClearsPasswordScheme), which is what retires a wrapped row.
     */
    private function store(Member $member, string $password): Member
    {
        $member->forceFill(['password' => Hash::make($password)])->save();

        return $member;
    }
}
