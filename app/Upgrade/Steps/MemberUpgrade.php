<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `member` → OpenPNE 4 `members` (minimal).
 *
 * Maps the member-table-native fields and preserves id (every relation and diary FK references it)
 * and timestamps. OpenPNE 3 keeps credentials in member_config, not the member table, so email and
 * password are recorded as pending targets rather than mapped: the step is intentionally not
 * runnable until that source (and the legacy-hash handling) is resolved.
 */
class MemberUpgrade extends UpgradeStep
{
    protected string $source = 'member';

    protected string $target = 'members';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'name' => Column::source('name'),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }

    public function targetDefaults(): array
    {
        // No OpenPNE 3 source; rely on the schema default (null).
        return ['email_verified_at', 'remember_token'];
    }

    public function pendingTargets(): array
    {
        return [
            'email' => 'Login email is not a member-table column; OpenPNE 3 keeps the address in member_config. Source/join deferred.',
            'password' => 'Credentials live in member_config, not the member table; carrying the hash for rehash-on-login is deferred.',
        ];
    }

    public function gaps(): array
    {
        return [
            'invite_member_id' => 'Inviter reference; no corresponding column in the current members schema.',
            'is_login_rejected' => 'Login-rejection flag; no corresponding column in the current members schema.',
            'is_active' => 'Account status flag; no corresponding column in the current members schema.',
        ];
    }
}
