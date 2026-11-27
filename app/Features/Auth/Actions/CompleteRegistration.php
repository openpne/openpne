<?php

namespace App\Features\Auth\Actions;

use App\Actions\Fortify\CreateNewMember;
use App\Features\Auth\Events\MemberRegistered;
use App\Features\Auth\MemberCapacity;
use App\Features\Auth\MemberCapacityReached;
use App\Features\Auth\RegistrationLinkUnusable;
use App\Features\Auth\RegistrationMode;
use App\Features\Auth\RegistrationTokenSource;
use App\Features\Group\Actions\JoinDefaultGroups;
use App\Models\AdminRoleInvite;
use App\Models\Member;
use App\Models\RegistrationToken;
use App\Support\Feature;
use App\Support\SecurityLog;
use App\Support\ViewerRelations;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The member is created and the token consumed in one transaction, so a token never outlives the
 * account it created and a failure never leaves a member without burning the token. The token's
 * email is forced over whatever the form posted: the address is the one the link was issued for, not
 * one re-entered.
 */
class CompleteRegistration
{
    public function __construct(private CreateNewMember $create, private MemberCapacity $capacity, private JoinDefaultGroups $joinDefaultGroups) {}

    /**
     * @param  array<string, mixed>  $input  the posted form (name, password, profile); email is ignored
     *
     * @throws ValidationException name/password/profile failed validation
     * @throws QueryException the email was claimed between check and insert, or another write failed
     * @throws MemberCapacityReached no seat left; the token is kept so the link works once one frees up
     * @throws RegistrationLinkUnusable the link was replaced, expired or is refused by the mode since the caller read it
     */
    public function __invoke(RegistrationToken $pending, array $input): Member
    {
        $member = $this->capacity->reserveMember(fn (): Member => DB::transaction(function () use ($pending, $input): Member {
            // Re-read under the lock admin:invite and every other link writer take, so the link and the invite are judged as they stand at the insert (docs/internals/administrators.md, "Inviting an administrator").
            $link = RegistrationToken::whereKey($pending->getKey())->where('token', $pending->token)->lockForUpdate()->first();
            if ($link === null || ! $link->isLive()) {
                throw new RegistrationLinkUnusable(refused: false);
            }

            $invite = AdminRoleInvite::whereKey($link->email)->lockForUpdate()->first();
            $inviteLive = $invite !== null && $invite->isLive();
            if (! RegistrationMode::current()->allows($link->source, $inviteLive)) {
                throw new RegistrationLinkUnusable(refused: true);
            }

            $member = $this->create->create(['email' => $link->email] + $input);
            if ($inviteLive) {
                $member->forceFill(['admin_granted_at' => now(), 'admin_generation' => 1])->save();
                $invite->delete();
            }
            $this->autoFriendInviter($link, $member);
            ($this->joinDefaultGroups)($member);
            $link->delete();

            // Fires after this transaction commits (ShouldDispatchAfterCommit), so the queued
            // registration-complete mail never references a not-yet-durable member row.
            MemberRegistered::dispatch($member);

            return $member;
        }));

        if ($member->isAdministrator()) {
            SecurityLog::event('admin.granted', ['member_id' => (int) $member->getKey(), 'actor' => 'cli', 'via' => 'invite']);
        }

        return $member;
    }

    /**
     * Friended at completion rather than at invite time as OpenPNE 3 did, since no member exists to
     * reference until now. The existence check covers a store with FK enforcement off, so a deleted
     * inviter never leaves a friendship pointing at no one.
     */
    private function autoFriendInviter(RegistrationToken $pending, Member $member): void
    {
        if ($pending->source !== RegistrationTokenSource::MemberInvite || $pending->inviter_id === null || ! Feature::Friend->enabled()) {
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
