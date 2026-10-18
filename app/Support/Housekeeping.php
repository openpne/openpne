<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\EmailChangeRequest;
use App\Models\MfaResetRequest;
use App\Models\RegistrationToken;

/**
 * The daily cleanup a host without cron would otherwise never run: the scheduler's `model:prune`
 * does the same work, so running both is harmless (docs/internals/runtime.md, "Scheduled tasks").
 */
class Housekeeping
{
    /** @return int rows pruned */
    public function run(): int
    {
        $pruned = 0;
        foreach ([RegistrationToken::class, EmailChangeRequest::class, MfaResetRequest::class] as $model) {
            // One statement per model; these rows have nothing to tear down, so pruneAll's row-by-row walk is not needed.
            $pruned += (new $model)->prunable()->delete();
        }

        return $pruned;
    }
}
