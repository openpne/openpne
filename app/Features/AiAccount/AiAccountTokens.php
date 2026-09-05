<?php

declare(strict_types=1);

namespace App\Features\AiAccount;

use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * The owner's credentials stand behind these tokens, so whatever ends the owner's other footholds
 * belongs beside a call to this. The owner row is locked before the accounts, so a mint racing the
 * sweep is serialized rather than slipping between the read and the delete.
 */
final class AiAccountTokens
{
    public static function revokeOwnedBy(Member $owner): void
    {
        DB::transaction(function () use ($owner): void {
            $locked = Member::whereKey($owner->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            foreach ($locked->aiAccounts()->lockForUpdate()->get() as $aiAccount) {
                $aiAccount->tokens()->delete();
            }
        });
    }
}
