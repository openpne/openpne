<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class GadgetConfigRow
{
    /**
     * Must run inside the transaction that then writes the row: `gadget_configs` has no unique key on
     * (gadget_id, name), so the gadget's row lock is what keeps two writers from both inserting it.
     */
    public static function lockedExists(int $gadgetId, string $name): bool
    {
        DB::table('gadgets')->where('id', $gadgetId)->lockForUpdate()->value('id');

        // A locking read: a plain one would answer from a snapshot taken before the lock was granted.
        return DB::table('gadget_configs')->where(['gadget_id' => $gadgetId, 'name' => $name])->lockForUpdate()->exists();
    }
}
