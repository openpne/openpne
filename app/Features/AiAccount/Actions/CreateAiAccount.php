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
 * Create an AI account owned by $owner: a member row with no email and no password, reachable only
 * through a personal access token the owner mints.
 *
 * Every precondition is re-read under an X lock on the OWNER row, never from the caller's snapshot.
 * That row is the serialization point the whole feature agrees on — a freeze, a withdrawal, another
 * creation and a token mint all take it first — so a creation cannot slip past a ban that committed
 * while the form was open, and two concurrent creations cannot both see "one under the cap".
 */
class CreateAiAccount
{
    public function __construct(private readonly AiAccountSettings $settings) {}

    public function __invoke(Member $owner, string $name): Member
    {
        $name = trim($name);

        // The name is a member's name, held to the same rule an ordinary member's is created under
        // — here rather than only in whatever form submitted it, so no caller can persist a nameless
        // or over-long member by skipping the form.
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

            // forceFill: owner_member_id is outside the model's mass-assignable set, because the link
            // is immutable — an account is created owned, and no path re-parents it afterwards.
            // email and password stay null, which the members CHECK also insists on.
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
