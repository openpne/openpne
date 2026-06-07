<?php

namespace App\Features\CommunityTopic\Exceptions;

use DomainException;

class CommunityTopicActionException extends DomainException
{
    public function __construct(public readonly CommunityTopicActionFailure $reason)
    {
        parent::__construct($reason->value);
    }
}
