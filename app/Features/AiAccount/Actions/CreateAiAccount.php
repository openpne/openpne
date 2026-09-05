<?php

declare(strict_types=1);

namespace App\Features\AiAccount\Actions;

use App\Features\AiAccount\AiAccountSettings;
use App\Features\AiAccount\Exceptions\AiAccountActionException;
use App\Features\AiAccount\Exceptions\AiAccountActionFailure;
use App\Features\Member\MemberNameRules;
use App\Models\Member;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Every precondition is re-read under an X lock on the owner row, never from the caller's snapshot
 * (docs/internals/mcp.md, "Running a bot member").
 */
class CreateAiAccount
{
    public function __construct(private readonly AiAccountSettings $settings) {}

    public function __invoke(Member $owner, string $name): Member
    {
        $name = trim($name);

        // Held to the same rule an ordinary member's name is, here and not only in the form, so no
        // caller can persist a nameless or over-long member.
        Validator::make(['name' => $name], ['name' => MemberNameRules::rules()])->validate();

        $aiAccount = DB::transaction(function () use ($owner, $name): Member {
            $locked = Member::whereKey($owner->getKey())->lockForUpdate()->firstOrFail();

            if (! $this->settings->enabled()) {
                throw new AiAccountActionException(AiAccountActionFailure::Disabled);
            }

            if ($locked->is_login_rejected) {
                throw new AiAccountActionException(AiAccountActionFailure::OwnerFrozen);
            }

            // Keeps the ownership graph one level deep, which is what lets the withdrawal cascade
            // terminate without a depth guard.
            if ($locked->isAiAccount()) {
                throw new AiAccountActionException(AiAccountActionFailure::OwnerIsAiAccount);
            }

            if ($locked->aiAccounts()->lockForUpdate()->count() >= $this->settings->limit()) {
                throw new AiAccountActionException(AiAccountActionFailure::LimitReached);
            }

            // `owner_member_id` is deliberately not mass-assignable, and email and password stay
            // null as the `members` CHECK insists.
            $aiAccount = new Member;
            $aiAccount->forceFill([
                'name' => $name,
                'owner_member_id' => $locked->getKey(),
            ])->save();

            return $aiAccount;
        });

        // After the commit: an audit line for a creation that did not happen would be worse than a
        // missing one for a creation that did.
        SecurityLog::event('ai_account.created', [
            'member_id' => $aiAccount->getKey(),
            'owner_id' => $owner->getKey(),
        ]);

        return $aiAccount;
    }
}
