<?php

declare(strict_types=1);

namespace App\Support;

/** Whether the member has yet to be told how a list row's actions are reached (docs/internals/reactions.md, "The row"). */
enum RowActionsHint: string
{
    case Shown = 'shown';

    case Dismissed = 'dismissed';
}
