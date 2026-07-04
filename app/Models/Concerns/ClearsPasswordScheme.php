<?php

namespace App\Models\Concerns;

/**
 * Invariant: a newly written password is a plain bcrypt of the plaintext, so any save
 * that changes `password` without explicitly setting `password_scheme` resets the
 * scheme to the default (null). Without this, a wrapped-MD5 account (PasswordScheme)
 * that goes through a password reset would keep its stale scheme and be locked out —
 * login would md5() the attempt against a hash of the raw plaintext. The upgrade's
 * wrap pass writes through the query builder, not the model, so it is unaffected.
 */
trait ClearsPasswordScheme
{
    protected static function bootClearsPasswordScheme(): void
    {
        static::saving(function ($model): void {
            if ($model->isDirty('password') && ! $model->isDirty('password_scheme')) {
                $model->password_scheme = null;
            }
        });
    }
}
