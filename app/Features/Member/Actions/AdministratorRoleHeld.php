<?php

namespace App\Features\Member\Actions;

use RuntimeException;

/** An administrator is lost only by a revoke, which keeps the last one (docs/internals/administrators.md, "Who can hold the role"). */
class AdministratorRoleHeld extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('An administrator cannot be withdrawn or have login rejected; revoke the role first.');
    }
}
