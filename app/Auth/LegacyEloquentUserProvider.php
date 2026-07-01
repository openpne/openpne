<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Support\Facades\Hash;

/**
 * An Eloquent user provider that also accepts an OpenPNE 3 legacy password: a bare, unsalted 32-char
 * MD5 hex that the upgrade carries verbatim into the password column (Hash::check cannot verify it).
 *
 * Only validateCredentials() is overridden, and it is deliberately side-effect-free: a matched legacy
 * hash is NOT rewritten here. The bcrypt upgrade is left to the inherited rehashPasswordIfRequired(),
 * which the guard calls only after the login is authorized (Filament's login page calls
 * validateCredentials() up front, before its access check), so a correct password never writes to the
 * database before the login is actually granted. needsRehash() reports true for a non-bcrypt string,
 * so that inherited path upgrades the MD5 to bcrypt after a successful legacy login — the weak hash is
 * gone after the first login, mirroring the member flow (App\Actions\Fortify\AuthenticateMember).
 */
class LegacyEloquentUserProvider extends EloquentUserProvider
{
    public function validateCredentials(UserContract $user, #[\SensitiveParameter] array $credentials)
    {
        $hashed = $user->getAuthPassword();

        // A recognised hash (bcrypt) → the framework check; any cost-bump rehash stays on the guard's
        // standard rehashPasswordIfRequired path.
        if (is_string($hashed) && $hashed !== '' && Hash::isHashed($hashed)) {
            return parent::validateCredentials($user, $credentials);
        }

        // OpenPNE 3 bare MD5. Verify only; the rehash happens after authorization (see class doc).
        $plain = $credentials['password'] ?? null;
        if ($plain === null || ! is_string($hashed) || $hashed === '') {
            return false;
        }

        return hash_equals($hashed, md5((string) $plain));
    }
}
