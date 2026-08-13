<?php

namespace App\Features\DirectMessage\Exceptions;

use DomainException;

class DirectMessageActionException extends DomainException
{
    public function __construct(public readonly DirectMessageActionFailure $reason)
    {
        parent::__construct($reason->value);
    }
}
