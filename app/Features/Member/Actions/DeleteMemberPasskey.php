<?php

namespace App\Features\Member\Actions;

use App\Auth\SessionRevocation;
use App\Models\Member;
use Illuminate\Support\Facades\DB;
use Laravel\Passkeys\Actions\DeletePasskey;
use Laravel\Passkeys\Passkey;

/**
 * Returns the removed passkey, or null when the member no longer holds that id — a concurrent double
 * delete must revoke, log and mail once.
 */
class DeleteMemberPasskey
{
    public function __construct(private readonly DeletePasskey $delete) {}

    public function __invoke(Member $viewer, int $passkeyId, ?string $exceptSessionId): ?Passkey
    {
        return DB::transaction(function () use ($viewer, $passkeyId, $exceptSessionId): ?Passkey {
            $fresh = Member::whereKey($viewer->getKey())->lockForUpdate()->firstOrFail();

            $passkey = $fresh->passkeys()->whereKey($passkeyId)->lockForUpdate()->first();
            if ($passkey === null) {
                return null;
            }

            ($this->delete)($fresh, $passkey);
            SessionRevocation::revokeMember($fresh, $exceptSessionId);

            return $passkey;
        });
    }
}
