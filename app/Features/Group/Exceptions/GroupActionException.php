<?php

namespace App\Features\Group\Exceptions;

use DomainException;

class GroupActionException extends DomainException
{
    public function __construct(public readonly GroupActionFailure $reason)
    {
        parent::__construct($reason->value);
    }
}
