<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Support\Facades\Hash;

/**
 * A PasswordScheme::Md5Bcrypt row verifies by md5()ing the attempt, and the inherited
 * needsRehash() would never retire it because a wrapped hash is itself a healthy bcrypt string.
 * validateCredentials() writes nothing, because Filament's login page calls it before its access
 * check; the rewrite waits for rehashPasswordIfRequired(), which the guard calls only once the
 * login is granted.
 */
class LegacyEloquentUserProvider extends EloquentUserProvider
{
    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials)
    {
        $user = parent::retrieveByCredentials($credentials);

        if ($user === null) {
            $this->rejectInConstantTime();
        }

        return $user;
    }

    public function validateCredentials(UserContract $user, #[\SensitiveParameter] array $credentials)
    {
        $hashed = $user->getAuthPassword();

        if (! is_string($hashed) || $hashed === '') {
            return $this->rejectInConstantTime();
        }

        if (! $this->usesWrappedScheme($user)) {
            // An unrecognised stored form — a bare MD5 the wrap pass has not converted —
            // authenticates nobody, and must not reach the bcrypt hasher, which throws
            // on foreign formats rather than returning false.
            if (! Hash::isHashed($hashed)) {
                return $this->rejectInConstantTime();
            }

            return parent::validateCredentials($user, $credentials);
        }

        $plain = $credentials['password'] ?? null;

        if (! is_string($plain)) {
            return $this->rejectInConstantTime();
        }

        return $this->hasher->check(md5($plain), $hashed);
    }

    /**
     * A rejection that skipped hash verification burns an equivalent bcrypt, so response time is
     * not an account-existence oracle. A fixed input rather than the attempt: a configured
     * BCRYPT_LIMIT would let an over-long attempt throw on exactly this path.
     */
    private function rejectInConstantTime(): bool
    {
        $this->hasher->make('openpne-timing-equalizer');

        return false;
    }

    public function rehashPasswordIfRequired(UserContract $user, #[\SensitiveParameter] array $credentials, bool $force = false)
    {
        if (! $this->usesWrappedScheme($user)) {
            parent::rehashPasswordIfRequired($user, $credentials, $force);

            return;
        }

        // The save also clears password_scheme (ClearsPasswordScheme), so the next login verifies
        // the plain bcrypt directly.
        $user->forceFill(['password' => Hash::make($credentials['password'])])->save();
    }

    private function usesWrappedScheme(UserContract $user): bool
    {
        return $user->password_scheme === PasswordScheme::Md5Bcrypt->value;
    }
}
