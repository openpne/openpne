<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `admin_user` → OpenPNE 4 `admin_users`. The password copies verbatim as the OpenPNE 3 MD5
 * (INSERT...SELECT bypasses the model's `hashed` cast); PasswordWrap converts it after the walk.
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
        // No OpenPNE 3 source: password_scheme is set by PasswordWrap, and the app_authentication_*
        // columns are MFA state an admin sets up after the move.
        return ['password_scheme', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'];
    }
}
