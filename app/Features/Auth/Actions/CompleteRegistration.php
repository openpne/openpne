<?php

namespace App\Features\Auth\Actions;

use App\Actions\Fortify\CreateNewMember;
use App\Features\Auth\Events\MemberRegistered;
use App\Features\Auth\RegistrationTokenSource;
use App\Models\Member;
use App\Models\RegistrationToken;
use App\Support\ViewerRelations;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The member is created and the token consumed in one transaction, so a token never outlives the
 * account it created and a failure never leaves a member without burning the token. The token's
 * email is forced over whatever the form posted: the address was proven by the mailed link, not
 * re-entered.
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

            // Fires after this transaction commits (ShouldDispatchAfterCommit), so the queued
            // registration-complete mail never references a not-yet-durable member row.
            MemberRegistered::dispatch($member);

            return $member;
        });
    }

    /**
     * Friended at completion rather than at invite time as OpenPNE 3 did, since no member exists to
     * reference until now. The existence check covers a store with FK enforcement off, so a deleted
     * inviter never leaves a friendship pointing at no one.
     */
    private function autoFriendInviter(RegistrationToken $pending, Member $member): void
    {
        if ($pending->source !== RegistrationTokenSource::MemberInvite || $pending->inviter_id === null) {
            return;
        }

        if (! Member::whereKey($pending->inviter_id)->exists()) {
            return;
        }

        // One timestamp for both halves of the mirror (see SendFriendRequest).
        $at = now();

        DB::table('friendships')->insert([
            ['member_id' => $pending->inviter_id, 'friend_id' => $member->getKey(), 'created_at' => $at],
            ['member_id' => $member->getKey(), 'friend_id' => $pending->inviter_id, 'created_at' => $at],
        ]);

        ViewerRelations::flush();
    }
}
