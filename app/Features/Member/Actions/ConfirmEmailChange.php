<?php

namespace App\Features\Member\Actions;

use App\Models\EmailChangeRequest;
use App\Models\Member;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Commits a pending email change and consumes its token in one transaction, so a token can never
 * outlive the change it made (single-use). remember_token is rotated in the same write, killing every
 * "remember me" cookie so the changed login identifier cannot be re-used silently. The members.email
 * unique index is the final TOCTOU guard: if the address was claimed between the controller's check
 * and here, the insert throws QueryException and the caller voids the dead pending row.
 *
 * @throws QueryException the new address was claimed between check and commit
 */
class ConfirmEmailChange
{
    public function __invoke(EmailChangeRequest $pending): Member
    {
        return DB::transaction(function () use ($pending): Member {
            $member = Member::whereKey($pending->member_id)->lockForUpdate()->firstOrFail();

            $member->forceFill([
                'email' => $pending->new_email,
                'remember_token' => Str::random(60),
            ])->save();

            $pending->delete();

            return $member;
        });
    }
}
