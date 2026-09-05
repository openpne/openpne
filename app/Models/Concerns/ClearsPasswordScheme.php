<?php

namespace App\Models\Concerns;

/**
 * A save that changes `password` without also setting `password_scheme` resets the scheme to null,
 * so a wrapped-MD5 account that resets its password is not locked out by a stale scheme. The
 * upgrade's wrap pass writes through the query builder rather than the model and is deliberately
 * unaffected.
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
