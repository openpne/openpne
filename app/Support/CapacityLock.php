<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The cap's own sns_settings row is the lock: whoever holds it reads the live counts, decides, and
 * writes inside one transaction, so two writers cannot both see the last seat free
 * (docs/internals/capacity.md, "Reservation").
 */
final class CapacityLock
{
    /**
     * Runs $body with the cap as the locked row holds it (null when no row, which is no cap); the
     * cached value is only good enough to decide whether to take the lock at all.
     *
     * @template T
     *
     * @param  Closure(?int): T  $body
     * @return T
     */
    public static function hold(SnsSettingKey $key, Closure $body): mixed
    {
        return DB::transaction(function () use ($key, $body): mixed {
            $row = DB::table('sns_settings')->where('key', $key->value)->lockForUpdate()->first();

            return $body($row === null ? null : $key->decode((string) $row->value));
        });
    }
}
