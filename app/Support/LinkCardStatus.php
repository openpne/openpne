<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Only Ok renders, and a page view never turns into a fetch: Pending means a worker has been asked,
 * Failed means `next_attempt_at` decides when to ask again. Internal is a URL of this site's own,
 * never fetched; its card is assembled at render time from the record the row points at.
 */
enum LinkCardStatus: string
{
    case Pending = 'pending';

    case Ok = 'ok';

    case Failed = 'failed';

    case Internal = 'internal';
}
