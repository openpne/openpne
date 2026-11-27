<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AdminPasswordSetupToken;
use App\Models\AdminRoleInvite;
use App\Models\EmailChangeRequest;
use App\Models\MfaResetRequest;
use App\Models\RegistrationToken;

/**
 * The daily cleanup a host without cron would otherwise never run: the scheduler's `model:prune` and
 * `openpne:prune-oauth` do the same work, so running both is harmless (docs/internals/runtime.md,
 * "Scheduled tasks").
 */
class Housekeeping
{
    /** The scheduler's daily `model:prune` sweeps the same list. */
    public const PRUNED = [RegistrationToken::class, EmailChangeRequest::class, MfaResetRequest::class, AdminPasswordSetupToken::class, AdminRoleInvite::class];

    /** @return int rows pruned */
    public function run(): int
    {
        $pruned = 0;
        foreach (self::PRUNED as $model) {
            // One statement per model; these rows have nothing to tear down, so pruneAll's row-by-row walk is not needed.
            $pruned += (new $model)->prunable()->delete();
        }

        return $pruned + PruneOAuth::run();
    }
}
