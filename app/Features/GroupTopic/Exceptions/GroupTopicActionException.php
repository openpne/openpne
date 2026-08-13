<?php

namespace App\Features\GroupTopic\Exceptions;

use DomainException;

class GroupTopicActionException extends DomainException
{
    public function __construct(public readonly GroupTopicActionFailure $reason)
    {
        parent::__construct($reason->value);
    }
}
