<?php

namespace App\Features\Community\Exceptions;

use DomainException;

class CommunityActionException extends DomainException
{
    public function __construct(public readonly CommunityActionFailure $reason)
    {
        parent::__construct($reason->value);
    }
}
