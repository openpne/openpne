<?php

declare(strict_types=1);

namespace App\Features\AiAccount\Actions;

use App\Features\AiAccount\Exceptions\AiAccountActionException;
use App\Features\AiAccount\Exceptions\AiAccountActionFailure;
use App\Features\Member\Actions\WithdrawMember;
use App\Models\Member;
use App\Support\SecurityLog;

/**
 * Deletion is member withdrawal rather than a second delete path, and is deliberately not gated on
 * the site setting: switching AI accounts off must not trap the ones already out there.
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

        // Beside the `member.withdrawn` the withdrawal logs: that one records the row going away,
        // this one whose account it was.
        SecurityLog::event('ai_account.deleted', [
            'member_id' => $aiAccountId,
            'owner_id' => $owner->getKey(),
        ]);
    }
}
