<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Where a link card is in its fetch lifecycle.
 *
 * Only Ok renders. Pending and Failed exist so a page view never turns into a fetch attempt: Pending
 * says a worker has been asked, Failed says the last attempt did not produce anything usable and
 * `next_attempt_at` governs when it is worth asking again.
 *
 * Internal is outside that lifecycle altogether. It marks a URL of this site's own, which is never
 * fetched and whose card is assembled from the record it names at render time
 * ([link-cards.md](../../docs/internals/link-cards.md)); the row holds a pointer and nothing else.
 */
enum LinkCardStatus: string
{
    case Pending = 'pending';

    case Ok = 'ok';

    case Failed = 'failed';

    case Internal = 'internal';
}
