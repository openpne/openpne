<?php

namespace App\Features\GroupTalk\Exceptions;

use DomainException;

class GroupTalkActionException extends DomainException
{
    public function __construct(public readonly GroupTalkActionFailure $reason)
    {
        parent::__construct($reason->value);
    }
}
