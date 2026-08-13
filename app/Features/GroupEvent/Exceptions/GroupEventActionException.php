<?php

namespace App\Features\GroupEvent\Exceptions;

use DomainException;

class GroupEventActionException extends DomainException
{
    public function __construct(public readonly GroupEventActionFailure $reason)
    {
        parent::__construct($reason->value);
    }
}
