<?php

namespace App\Features\Block\Exceptions;

use DomainException;

class BlockActionException extends DomainException
{
    public function __construct(public readonly BlockActionFailure $reason)
    {
        parent::__construct($reason->value);
    }
}
