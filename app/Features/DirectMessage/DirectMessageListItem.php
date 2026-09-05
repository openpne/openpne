<?php

namespace App\Features\DirectMessage;

use App\Models\Member;
use Carbon\CarbonInterface;

/**
 * `counterparty` is the From (inbox) or To (sent/draft) member, null when that member was deleted.
 * `unread` is the inbox's state alone, and false in every other box.
 */
final readonly class DirectMessageListItem
{
    public function __construct(
        public int $messageId,
        public ?Member $counterparty,
        public string $subject,
        public CarbonInterface $date,
        public bool $unread,
        public DirectMessageRowStatus $status,
    ) {}
}
