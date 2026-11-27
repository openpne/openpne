<?php

declare(strict_types=1);

namespace App\Features\Member;

use App\Models\Member;
use Illuminate\Support\Collection;

/**
 * Every grant and revoke takes these locks first and the target member's row after.
 * On InnoDB a revoke's write to the index can still deadlock with a writer queued on these gaps, so the caller's transaction must retry (docs/internals/administrators.md, "Locks").
 */
final class AdministratorLock
{
    /**
     * Call it inside the transaction that writes the role.
     *
     * @return Collection<int, int> the administrators' ids, read with a locking read
     */
    public static function hold(): Collection
    {
        return Member::query()
            ->whereNotNull('admin_granted_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id);
    }
}
