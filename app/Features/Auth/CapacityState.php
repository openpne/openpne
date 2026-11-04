<?php

declare(strict_types=1);

namespace App\Features\Auth;

enum CapacityState
{
    case Unlimited;

    case Ok;

    /** Five seats or fewer left. */
    case Low;

    case Full;

    /** More members than the cap allows: the cap was lowered under a larger site. */
    case Over;
}
