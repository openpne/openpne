<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `member` → OpenPNE 4 `members`.
 *
 * The login email and password hash are not member-table columns in OpenPNE 3 — they live
 * in the `member_config` KV table — so they are pulled in with correlated subqueries:
 *
 *  - email: PC address, falling back to the mobile address; neither present (e.g. an
 *    inactive pre-registration that only has `pc_address_pre`) yields NULL, i.e. a
 *    login-impossible member whose row is still preserved.
 *  - password: the bare 32-char MD5. INSERT...SELECT bypasses Eloquent, so the model's
 *    `hashed` cast does not fire and the legacy hash lands verbatim, to be rehashed to
 *    bcrypt on the member's first login.
 *
 * The subqueries name `member_config` unqualified, so (unlike the FROM table) they are not
 * rewritten for a source prefix or a separate source database — acceptable for the fleet
 * (empty prefix, same database); see StepRegistry for the wider table-coverage gap.
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
            'email' => Column::expr($this->memberConfigCoalesce('pc_address', 'mobile_address'), uses: ['id']),
            'password' => Column::expr($this->memberConfigValue('password'), uses: ['id']),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }

    public function targetDefaults(): array
    {
        // No OpenPNE 3 source; rely on the schema default (null).
        return ['email_verified_at', 'remember_token'];
    }

    public function gaps(): array
    {
        return [
            'invite_member_id' => 'Inviter reference; no corresponding column in the current members schema.',
            'is_login_rejected' => 'Login-rejection flag; no corresponding column in the current members schema.',
            'is_active' => 'Account status flag; no corresponding column in the current members schema.',
        ];
    }

    /** First non-empty `member_config` value across the given names, else NULL. */
    private function memberConfigCoalesce(string ...$names): string
    {
        $parts = array_map(
            fn (string $name): string => "NULLIF({$this->memberConfigValue($name)}, '')",
            $names,
        );

        return 'COALESCE('.implode(', ', $parts).')';
    }

    private function memberConfigValue(string $name): string
    {
        return "(SELECT `value` FROM `member_config` WHERE `member_id` = `member`.`id` AND `name` = '{$name}' LIMIT 1)";
    }
}
