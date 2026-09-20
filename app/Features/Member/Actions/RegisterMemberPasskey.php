<?php

namespace App\Features\Member\Actions;

use App\Models\Member;
use Illuminate\Support\Facades\DB;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Passkey;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;

class RegisterMemberPasskey
{
    public function __construct(private readonly StorePasskey $store) {}

    public function __invoke(Member $viewer, string $name, PublicKeyCredential $credential, PublicKeyCredentialCreationOptions $options): Passkey
    {
        return DB::transaction(function () use ($viewer, $name, $credential, $options): Passkey {
            $fresh = Member::whereKey($viewer->getKey())->lockForUpdate()->firstOrFail();

            // An AI account has no session to reach here; the refusal is defense in depth, so a
            // credential-less row never gains a credential.
            abort_if($fresh->isAiAccount(), 403);

            return ($this->store)($fresh, $name, $credential, $options);
        });
    }
}
