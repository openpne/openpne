<?php

namespace App\Features\Diary\Exceptions;

use RuntimeException;

class DiaryActionException extends RuntimeException
{
    public function __construct(public readonly DiaryActionFailure $reason)
    {
        parent::__construct($reason->name);
    }
}
