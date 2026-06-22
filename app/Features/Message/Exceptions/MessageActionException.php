<?php

namespace App\Features\Message\Exceptions;

use DomainException;

class MessageActionException extends DomainException
{
    public function __construct(public readonly MessageActionFailure $reason)
    {
        parent::__construct($reason->value);
    }
}
