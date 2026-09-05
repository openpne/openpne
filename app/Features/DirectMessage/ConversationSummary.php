<?php

namespace App\Features\DirectMessage;

use App\Models\DirectMessage;
use App\Models\Member;

/**
 * A null `counterpart` is the withdrawn bucket. `latest` is never null: a conversation exists
 * because a message in it does.
 */
final readonly class ConversationSummary
{
    public function __construct(
        public ?Member $counterpart,
        public DirectMessage $latest,
        public int $unread,
    ) {}
}
