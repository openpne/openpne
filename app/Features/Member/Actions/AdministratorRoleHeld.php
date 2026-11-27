<?php

namespace App\Features\Member\Actions;

use RuntimeException;

/** Thrown by WithdrawMember and RejectMemberLogin: the only way an administrator is lost is RevokeAdministrator. */
class AdministratorRoleHeld extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('An administrator cannot be withdrawn or have login rejected; revoke the role first.');
    }
}
