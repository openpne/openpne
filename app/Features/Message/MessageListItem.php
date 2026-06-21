<?php

namespace App\Features\Message;

use App\Models\Member;
use Carbon\CarbonInterface;

/**
 * One row in a message box list, normalized across the four boxes so the list view renders them
 * uniformly. `counterparty` is the From (inbox) or To (sent/draft) member, null when that member
 * was deleted. `messageId` keys the show route.
 */
final readonly class MessageListItem
{
    public function __construct(
        public int $messageId,
        public ?Member $counterparty,
        public string $subject,
        public CarbonInterface $date,
        public bool $unread,
    ) {}
}
