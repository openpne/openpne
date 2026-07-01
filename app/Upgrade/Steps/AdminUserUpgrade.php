<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `admin_user` → OpenPNE 4 `admin_users`.
 *
 * The password copies verbatim: it is an OpenPNE 3 MD5 hash, and INSERT...SELECT bypasses the model's
 * `hashed` cast so it lands unchanged, to be rehashed to bcrypt on the admin's first login (the admin
 * guard's legacy-hash handling). `remember_token` has no OpenPNE 3 source and defaults to null.
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
        return ['remember_token'];
    }
}
