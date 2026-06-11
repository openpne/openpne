<?php

namespace App\Features\Auth\Actions;

use App\Actions\Fortify\CreateNewMember;
use App\Features\Auth\RegistrationTokenSource;
use App\Models\Member;
use App\Models\RegistrationToken;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates the member for a confirmed registration and consumes its token in one transaction, so a
 * token can never outlive the account it created (single-use) nor leave a member without burning the
 * token. The token's email is authoritative — it is forced onto the input, overriding anything the
 * form posted, since the address was proven by the mailed link, not re-entered. A member-invite token
 * also auto-friends the new member with its inviter, here at completion (OpenPNE 3 friended at invite
 * time, but there is no member to reference until completion).
 */
class CompleteRegistration
{
    public function __construct(private CreateNewMember $create) {}

    /**
     * @param  array<string, mixed>  $input  the posted form (name, password, profile); email is ignored
     *
     * @throws ValidationException name/password/profile failed validation
     * @throws QueryException the email was claimed between check and insert
     */
    public function __invoke(RegistrationToken $pending, array $input): Member
    {
        return DB::transaction(function () use ($pending, $input): Member {
            $member = $this->create->create(['email' => $pending->email] + $input);
            $this->autoFriendInviter($pending, $member);
            $pending->delete();

            return $member;
        });
    }

    /**
     * Friends the new member with the member-invite's inviter (bidirectional mirror, the same shape as
     * accepting a request). The inviter is null on a self/admin token or once the inviter is deleted
     * (the FK nulls inviter_id); the existence check also covers a store with FK enforcement off, so a
     * deleted inviter never leaves a friendship pointing at no one.
     */
    private function autoFriendInviter(RegistrationToken $pending, Member $member): void
    {
        if ($pending->source !== RegistrationTokenSource::MemberInvite || $pending->inviter_id === null) {
            return;
        }

        if (! Member::whereKey($pending->inviter_id)->exists()) {
            return;
        }

        DB::table('friendships')->insert([
            ['member_id' => $pending->inviter_id, 'friend_id' => $member->getKey()],
            ['member_id' => $member->getKey(), 'friend_id' => $pending->inviter_id],
        ]);
    }
}
