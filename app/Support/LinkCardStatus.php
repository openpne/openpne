<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Where a link card is in its fetch lifecycle.
 *
 * Only Ok renders. The other two exist so a page view never turns into a fetch attempt: Pending says
 * a worker has been asked, Failed says the last attempt did not produce anything usable and
 * `next_attempt_at` governs when it is worth asking again.
 */
enum LinkCardStatus: string
{
    case Pending = 'pending';

    case Ok = 'ok';

    case Failed = 'failed';
}
