<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Support\Facades\Hash;

/**
 * An Eloquent user provider that also accepts a wrapped OpenPNE 3 password: the upgrade
 * stores bcrypt over the legacy MD5 hex and flags the row (PasswordScheme::Md5Bcrypt),
 * so verification md5()s the attempt first. A row without the flag verifies as plain
 * bcrypt — an unconverted bare MD5 authenticates nobody.
 *
 * validateCredentials() stays deliberately side-effect-free: a matched wrapped hash is
 * NOT rewritten there. The upgrade to a plain bcrypt happens in
 * rehashPasswordIfRequired(), which the guard calls only after the login is authorized
 * (Filament's login page calls validateCredentials() up front, before its access
 * check), so a correct password never writes to the database before the login is
 * actually granted. The override is needed because a wrapped hash IS a healthy bcrypt
 * string — the inherited needsRehash() would leave it, and its flag, in place forever.
 */
class LegacyEloquentUserProvider extends EloquentUserProvider
{
    public function validateCredentials(UserContract $user, #[\SensitiveParameter] array $credentials)
    {
        $hashed = $user->getAuthPassword();

        if (! is_string($hashed) || $hashed === '') {
            return false;
        }

        if (! $this->usesWrappedScheme($user)) {
            // An unrecognised stored form — a bare MD5 the wrap pass has not converted —
            // authenticates nobody, and must not reach the bcrypt hasher, which throws
            // on foreign formats rather than returning false.
            if (! Hash::isHashed($hashed)) {
                return false;
            }

            return parent::validateCredentials($user, $credentials);
        }

        $plain = $credentials['password'] ?? null;

        if (! is_string($plain)) {
            return false;
        }

        return $this->hasher->check(md5($plain), $hashed);
    }

    public function rehashPasswordIfRequired(UserContract $user, #[\SensitiveParameter] array $credentials, bool $force = false)
    {
        if (! $this->usesWrappedScheme($user)) {
            parent::rehashPasswordIfRequired($user, $credentials, $force);

            return;
        }

        // Retire the wrapper: store a plain bcrypt of the plaintext. The save clears
        // password_scheme (ClearsPasswordScheme), so the next login verifies directly.
        $user->forceFill(['password' => Hash::make($credentials['password'])])->save();
    }

    private function usesWrappedScheme(UserContract $user): bool
    {
        return $user->password_scheme === PasswordScheme::Md5Bcrypt->value;
    }
}
