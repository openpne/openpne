<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `admin_user` → OpenPNE 4 `admin_users`.
 *
 * The password copies verbatim: it is an OpenPNE 3 MD5 hash, and INSERT...SELECT bypasses the model's
 * `hashed` cast so it lands unchanged here; the runner's post-walk wrap pass (PasswordWrap) then
 * converts it to bcrypt(md5) + password_scheme before the run completes, so no bare MD5 survives at
 * rest. `remember_token` has no OpenPNE 3 source and defaults to null.
 */
class AdminUserUpgrade extends UpgradeStep
{
    protected string $source = 'admin_user';

    protected string $target = 'admin_users';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'username' => Column::source('username'),
            'password' => Column::source('password'),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }

    public function targetDefaults(): array
    {
        // password_scheme is set by the runner's post-walk wrap pass, not this step.
        return ['password_scheme', 'remember_token'];
    }
}
