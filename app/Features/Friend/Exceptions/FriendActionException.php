<?php

namespace App\Features\Friend\Exceptions;

use DomainException;

class FriendActionException extends DomainException
{
    public function __construct(public readonly FriendActionFailure $reason)
    {
        parent::__construct($reason->value);
    }
}
