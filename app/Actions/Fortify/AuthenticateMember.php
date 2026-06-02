<?php

namespace App\Actions\Fortify;

use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Fortify;

/**
 * Validates member login credentials, upgrading a legacy OpenPNE 3 password to bcrypt
 * on the way through.
 *
 * OpenPNE 3 stored a bare, unsalted MD5 that the upgrade carries verbatim into
 * members.password. Hash::check cannot verify that, so this callback (wired via
 * Fortify::authenticateUsing) detects the legacy form, verifies it, and rehashes to
 * bcrypt in place — the weak hash is gone after the member's first successful login.
 */
class AuthenticateMember
{
    public function __invoke(Request $request): ?Member
    {
        // CanonicalizeUsername has already lowercased the username earlier in the pipeline.
        $member = Member::query()
            ->where('email', $request->input(Fortify::username()))
            ->first();

        if (! $member || $member->password === null) {
            return null;
        }

        $password = (string) $request->input('password');

        return Hash::isHashed($member->password)
            ? $this->verifyCurrent($member, $password)
            : $this->verifyLegacy($member, $password);
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

    /** OpenPNE 3 hashes are bare 32-char MD5 hex, which Hash::isHashed does not recognise. */
    private function verifyLegacy(Member $member, string $password): ?Member
    {
        if (! hash_equals($member->password, md5($password))) {
            return null;
        }

        return $this->store($member, $password);
    }

    /**
     * Persist a freshly hashed password. Hash explicitly rather than leaning on the model's
     * `hashed` cast: the cast leaves an already-hash-shaped string untouched, so passing the
     * raw plaintext could skip hashing for a password that happens to look like a hash. This
     * mirrors Laravel's EloquentUserProvider::rehashPasswordIfRequired.
     */
    private function store(Member $member, string $password): Member
    {
        $member->forceFill(['password' => Hash::make($password)])->save();

        return $member;
    }
}
