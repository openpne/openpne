<?php

namespace App\Features\CommunityEvent\Exceptions;

use DomainException;

class CommunityEventActionException extends DomainException
{
    public function __construct(public readonly CommunityEventActionFailure $reason)
    {
        parent::__construct($reason->value);
    }
}
