<?php

declare(strict_types=1);

namespace App\Features\AiAccount\Actions;

use App\Features\AiAccount\Exceptions\AiAccountActionException;
use App\Features\AiAccount\Exceptions\AiAccountActionFailure;
use App\Features\Member\Actions\WithdrawMember;
use App\Models\Member;
use App\Support\SecurityLog;

/**
 * Retire an AI account at its owner's request.
 *
 * Deletion is member withdrawal — the account IS a member, and its group seats, content, tokens and
 * feed rows have to go the same way anyone else's do — so this action is the ownership check plus a
 * hand-off, never a second delete path. Deliberately not gated on the site setting: an operator who
 * switched AI accounts off must not thereby trap the ones already out there.
 */
class DeleteAiAccount
{
    public function __construct(private readonly WithdrawMember $withdrawMember) {}

    public function __invoke(Member $owner, Member $aiAccount): void
    {
        if (! $aiAccount->isAiAccount() || (int) $aiAccount->owner_member_id !== (int) $owner->getKey()) {
            throw new AiAccountActionException(AiAccountActionFailure::NotOwned);
        }

        $aiAccountId = (int) $aiAccount->getKey();

        ($this->withdrawMember)($aiAccount);

        // Beside the `member.withdrawn` the withdrawal itself logs: that one records the row going
        // away, this one records whose account it was.
        SecurityLog::event('ai_account.deleted', [
            'member_id' => $aiAccountId,
            'owner_id' => $owner->getKey(),
        ]);
    }
}
