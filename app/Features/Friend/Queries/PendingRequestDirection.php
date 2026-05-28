<?php

namespace App\Features\Friend\Queries;

enum PendingRequestDirection: string
{
    case Sent = 'sent';
    case Received = 'received';
}
