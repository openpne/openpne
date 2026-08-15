<?php

declare(strict_types=1);

namespace App\Features\AiAccount;

use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * Drop the personal access tokens of every AI account a member owns.
 *
 * The owner's credentials are what stand behind those tokens, so anything that ends the owner's
 * other footholds has to end these as well: a ban, a forgotten-password reset, an in-session
 * password change. Sitting beside App\Auth\SessionRevocation in each of those paths, for the same
 * reason it does — the account keeps existing, its way in does not.
 *
 * The owner row is locked first, the accounts second: the order every write in this feature takes,
 * so a mint racing this sweep is serialized rather than slipping between the read and the delete.
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
