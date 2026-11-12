<?php

namespace App\Features\Member\Actions;

use App\Models\EmailChangeRequest;
use App\Models\Member;
use App\Models\MfaResetRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The `members.email` unique index is the final TOCTOU guard: the write throws and the caller voids
 * the dead pending row.
 *
 * @return Member|null null when the pending change was voided before commit
 *
 * @throws QueryException
 */
class ConfirmEmailChange
{
    public function __invoke(EmailChangeRequest $pending): ?Member
    {
        return DB::transaction(function () use ($pending): ?Member {
            $locked = EmailChangeRequest::where('token', $pending->token)->lockForUpdate()->first();
            if ($locked === null) {
                return null;
            }

            $member = Member::whereKey($locked->member_id)->lockForUpdate()->firstOrFail();

            $member->forceFill(['email' => $locked->new_email])->save();

            // The address is the proof channel for a reset link, so changing it voids any pending one
            // (docs/internals/security.md, "Member two-factor authentication").
            MfaResetRequest::where('member_id', $member->getKey())->delete();

            $locked->delete();

            return $member;
        });
    }
}
