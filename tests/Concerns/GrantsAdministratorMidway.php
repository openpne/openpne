<?php

namespace Tests\Concerns;

use App\Models\Member;
use Illuminate\Support\Facades\DB;

trait GrantsAdministratorMidway
{
    /** Grants the role just before the first read of $member's row inside a transaction opened after this call. */
    private function grantBeforeTheLockedRead(Member $member): void
    {
        $baseline = DB::transactionLevel();
        $done = false;

        DB::beforeExecuting(function (string $sql) use ($member, $baseline, &$done): void {
            if ($done || DB::transactionLevel() <= $baseline || ! preg_match('/from [`"]members[`"] where [`"]members[`"]\.[`"]id[`"] = \?/', $sql)) {
                return;
            }
            $done = true;
            DB::table('members')->where('id', $member->getKey())->update(['admin_granted_at' => now(), 'admin_generation' => 1]);
        });
    }
}
