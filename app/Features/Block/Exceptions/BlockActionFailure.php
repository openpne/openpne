<?php

namespace App\Features\Block\Exceptions;

enum BlockActionFailure: string
{
    case SelfBlock = 'self_block';
    case AlreadyBlocked = 'already_blocked';
    case NotBlocked = 'not_blocked';
}
