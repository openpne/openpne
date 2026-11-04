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
     * Runs $body with the cap as the locked row holds it (null when no row), taken even when the
     * cache says no cap. Call it before any other read of the transaction: InnoDB fixes the
     * snapshot of $body's counts at the transaction's first consistent read.
     *
     * @template T
     *
     * @param  Closure(?int): T  $body
     * @return T
     */
    public static function hold(SnsSettingKey $key, Closure $body): mixed
    {
        // SQLite promotes the read to a write here and refuses a second promoter outright
        // ("database is locked"), which Laravel retries as a concurrency error.
        return DB::transaction(function () use ($key, $body): mixed {
            $row = DB::table('sns_settings')->where('key', $key->value)->lockForUpdate()->first();

            return $body($row === null ? null : $key->decode((string) $row->value));
        }, attempts: 3);
    }
}
