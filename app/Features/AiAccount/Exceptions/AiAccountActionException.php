<?php

declare(strict_types=1);

namespace App\Features\AiAccount\Exceptions;

use DomainException;

class AiAccountActionException extends DomainException
{
    public function __construct(public readonly AiAccountActionFailure $reason)
    {
        parent::__construct($reason->value);
    }
}
